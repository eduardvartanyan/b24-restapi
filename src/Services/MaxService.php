<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use App\Repositories\ChatRequestRepository;
use App\Repositories\ChatSourceRepository;
use App\Repositories\ChatStateRepository;
use App\Support\MessageCatalog;
use Bot;
use PHPMaxBot;
use PHPMaxBot\Exceptions\ApiException;
use PHPMaxBot\Exceptions\MaxBotException;
use PHPMaxBot\Helpers\Keyboard;
use Throwable;

readonly class MaxService
{
    private const int SOURCE_SMS = 2526;
    private const int SOURCE_QR = 2528;
    private const int SOURCE_ORGANIC = 2530;

    public function __construct(
        private B24Service $b24,
        private PHPMaxBot $maxBot,
        private ChatStateRepository $chatStateRepository,
        private ChatRequestRepository $chatRequestRepository,
        private ChatSourceRepository $chatSourceRepository,
        private DaDataService $daData,
        private MessageCatalog $messages
    ) { }

    public function handle(string $raw): array
    {
        $update = json_decode($raw, true);

        if (!is_array($update)) {
            Logger::error('Max webhook: invalid JSON', ['raw' => $raw]);
            return ['status' => 400, 'body' => 'Invalid update'];
        }

        Logger::info('[MaxService->handle] incoming update', [
            'update_type' => $update['update_type'] ?? null,
            'update'     => $update,
        ]);

        try {
            $this->registerHandlers();
            $this->registerActions();

            Bot::setMyCommands([
                [
                    'name' => 'menu',
                    'description' => 'Открыть команды'
                ],
            ]);

            $this->maxBot->command('menu', function() {
                return Bot::sendMessage($this->messages->get('message__menu'), [
                    'attachments' => [$this->getMenu()]
                ]);
            });

            $this->maxBot->start([
                'message_created',
                'message_callback',
                'bot_started'
            ]);

            return ['status' => 200, 'body' => 'OK'];
        } catch (ApiException $e) {
            Logger::error('Max webhook: API exception', [
                'message' => $e->getMessage(),
                'code'    => method_exists($e, 'getApiErrorCode') ? $e->getApiErrorCode() : null,
            ]);

            return ['status' => 500, 'body' => 'MAX API error'];
        } catch (MaxBotException $e) {
            Logger::error('Max webhook: library exception', [
                'message' => $e->getMessage(),
                'context' => method_exists($e, 'getContext') ? $e->getContext() : null,
            ]);

            return ['status' => 500, 'body' => 'Bot error'];
        } catch (Throwable $e) {
            Logger::error('Max webhook: unexpected exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return ['status' => 500, 'body' => 'Internal error'];
        }
    }

    private function registerHandlers(): void
    {
        $this->maxBot->on('bot_started', function () {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['chat_id'];

            if (!$this->chatSourceRepository->exists($chatId)) {
                if ($update['payload'] === 'qr') {
                    $source = $this::SOURCE_QR;
                } else {
                    $source = $update['payload'] ? $this::SOURCE_SMS : $this::SOURCE_ORGANIC;
                }
                $this->chatSourceRepository->create($chatId, $source);
            }
            if ($update['payload'] === 'qr') {
                $contactId = $this->b24->getContactIdByMaxChatId($chatId);
            } else {
                $contactId = $update['payload']
                    ? $this->b24->getContactIdByRid($update['payload'])
                    : $this->b24->getContactIdByMaxChatId($chatId);
            }

            Logger::info('[MaxService->registerHandlers] bot_started', [
                'chat_id'    => $chatId,
                'payload'    => $update['payload'] ,
                'contact_id' => $contactId ?? null,
            ]);

            if (isset($contactId)) {
                $this->b24->setMaxChatId(
                    $contactId,
                    $chatId,
                    source: $this->chatSourceRepository->getSource($chatId)
                );

                return Bot::sendMessage('Приветствую, ниже список доступных функций:', [
                    'attachments' => [$this->getMenu()]
                ]);
            }

            return Bot::sendMessage('Приветствую, чтобы начать общение, нажмите тут 👇',
                [
                    'attachments' => [Keyboard::inlineKeyboard([
                        [Keyboard::requestContact('Начать общение')],
                    ])],
                ]
            );
        });

        $this->maxBot->on('message_created', function () {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $userId = $update['message']['recipient']['user_id'];
            $chatState = $this->chatStateRepository->getState($chatId);
            $dtpRequest = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'dtp');

            Logger::info('Max webhook: message_created', [
                'chat_id'       => $chatId,
                'chat_state'    => $chatState,
            ]);

            if (
                isset($update['message']['body']['attachments'])
                && is_array($update['message']['body']['attachments'])
                && count($update['message']['body']['attachments'])
            ) {
                $attachment = $update['message']['body']['attachments'][0];
                if ($attachment['type'] === 'contact') {
                    $contact = $this->parseVCard($attachment['payload']['vcf_info']);
                    $contact['id'] = $this->b24->getContactIdByPhone($this->normalizePhone($contact['phone']));
                    if ($contact['id']) {
                        $this->b24->setMaxChatId(
                            $contact['id'],
                            $chatId,
                            source: $this->chatSourceRepository->getSource($chatId)
                        );
                    }

                    if ($chatState == 'dtp.waiting_contact') {
                        if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'dtp')) {
                            $this->chatStateRepository->saveStateForMinutes(
                                $chatId,
                                'dtp.waiting_confirmation',
                                30,
                                $userId,
                                [
                                    'type' => 'dtp',
                                    'request_id' => $request['id'],
                                ]
                            );
                            $payload = $request['payload'];
                            $contact['id'] = $payload['contact']['id'] ?? $contact['id'];
                            $payload['contact'] = $contact;
                            $this->chatRequestRepository->setPayload($request['id'], $payload);
                            $this->chatRequestRepository->setPhone($request['id'], $contact['phone']);
                            return Bot::sendMessage(
                                'Подтвердите заявку:' . PHP_EOL . $this->printDtpCard($payload),
                                [
                                    'attachments' => [Keyboard::inlineKeyboard([
                                        [Keyboard::callback(
                                            $this->messages->get('button_label__correct'),
                                            'request_confirmed'
                                        )],
                                        [Keyboard::callback(
                                            $this->messages->get('button_label__cancel'),
                                            'menu'
                                        )],
                                    ])],
                                ]
                            );
                        }
                    }

                    elseif ($chatState == 'status.waiting_contact') {
                        return Bot::sendMessage($this->b24->getDealsReportByContactId($contact['id']));
                    }

                    elseif ($chatState == 'ask.waiting_contact') {
                        if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'ask')) {
                            $payload = $request['payload'];
                            $contact['id'] = $payload['contact']['id'] ?? $contact['id'];
                            $payload['contact'] = $contact;
                            $this->chatRequestRepository->setPayload($request['id'], $payload);
                            $this->chatRequestRepository->setPhone($request['id'], $contact['phone']);
                            $this->chatStateRepository->saveStateForMinutes(
                                $chatId,
                                'ask.waiting_theme',
                                30,
                                $update['message']['recipient']['user_id'],
                                context: [
                                    'type' => 'ask',
                                    'request_id' => $request['id'],
                                ]
                            );
                            return Bot::sendMessage('По какой теме ваш вопрос?',
                                [
                                    'attachments' => [Keyboard::inlineKeyboard([
                                        [Keyboard::callback('ДТП', 'ask__dtp')],
                                        [Keyboard::callback('Юридические услуги', 'ask__law')],
                                        [Keyboard::callback('Экспертиза', 'ask__expertise')],
                                    ])],
                                ]
                            );
                        }
                    }

                    else {
                       return Bot::sendMessage('Доступные функции:', [
                            'attachments' => [$this->getMenu()]
                       ]);
                    }
                }
            }

            if ($chatState == 'dtp.waiting_address') {
                if (
                    isset($update['message']['body']['attachments'])
                    && is_array($update['message']['body']['attachments'])
                    && count($update['message']['body']['attachments'])
                ) {
                    $attachment = $update['message']['body']['attachments'][0];

                    if ($attachment['type'] === 'location') {
                        if ($dtpRequest) {
                            if ($address = $this->daData->getAddressByGeolocation(
                                $attachment['latitude'],
                                $attachment['longitude']
                            )) {
                                $payload = $dtpRequest['payload'];
                                $payload['location'] = [
                                    'lat' => $attachment['latitude'],
                                    'lon' => $attachment['longitude'],
                                    'address' => $address,
                                ];
                                $this->chatRequestRepository->setPayload($dtpRequest['id'], $payload);

                                return Bot::sendMessage(
                                    $this->messages->get('message__dtp_confirm_address') . $address,
                                    [
                                        'attachments' => [Keyboard::inlineKeyboard([
                                            [Keyboard::callback(
                                                $this->messages->get('button_label__correct'),
                                                'address_confirmed'
                                            )],
                                            [Keyboard::callback(
                                                $this->messages->get('button_label__manual_address'),
                                                'manual_address'
                                            )],
                                            [Keyboard::callback(
                                                $this->messages->get('button_label__cancel'),
                                                'menu'
                                            )],
                                        ])]
                                    ]
                                );
                            } else {
                                return Bot::sendMessage($this->messages->get('message__dtp_location_wrong'), [
                                        'attachments' => [Keyboard::inlineKeyboard([
                                            [Keyboard::callback(
                                                $this->messages->get('button_label__cancel'), 'menu'
                                            )],
                                        ])]
                                    ]
                                );
                            }
                        }
                    }
                } elseif (
                    isset($update['message']['body']['text'])
                    && $update['message']['body']['text'] !== ''
                ) {
                    if ($dtpRequest) {
                        $address = $this->daData->cleanAddress($update['message']['body']['text']);

                        if ($address) {
                            $payload = $dtpRequest['payload'];
                            $payload['location'] = ['address' => $address];
                            $this->chatRequestRepository->setPayload($dtpRequest['id'], $payload);

                            return Bot::sendMessage(
                                $this->messages->get('message__dtp_confirm_address') . $address,
                                [
                                    'attachments' => [Keyboard::inlineKeyboard([
                                        [Keyboard::callback(
                                            $this->messages->get('button_label__correct'),
                                            'address_confirmed'
                                        )],
                                        [Keyboard::callback(
                                            $this->messages->get('message__dtp_manual_address_again'),
                                            'manual_address'
                                        )],
                                        [Keyboard::requestGeoLocation($this->messages->get('button_label__geo'))],
                                        [Keyboard::callback(
                                            $this->messages->get('button_label__cancel'),
                                            'menu'
                                        )],
                                    ])],
                                ]
                            );
                        }
                    }
                }
            }

            if ($chatState == 'dtp.waiting_name') {
                if (
                    isset($update['message']['body']['text'])
                    && $update['message']['body']['text'] !== ''
                ) {
                    if ($dtpRequest) {
                        $this->chatStateRepository->saveStateForMinutes(
                            $chatId,
                            'dtp.waiting_phone',
                            30,
                            $userId,
                            [
                                'type' => 'dtp',
                                'request_id' => $dtpRequest['id'],
                            ]
                        );
                        $payload = $dtpRequest['payload'];
                        $payload['contact']['name'] = $update['message']['body']['text'];
                        $this->chatRequestRepository->setPayload($dtpRequest['id'], $payload);

                        return Bot::sendMessage(
                            $this->messages->get('message__dtp_phone'),
                            ['attachments' => [],]
                        );
                    }
                }
            }

            if ($chatState == 'dtp.waiting_phone') {
                if (
                    isset($update['message']['body']['text'])
                    && $update['message']['body']['text'] !== ''
                ) {
                    if ($dtpRequest) {
                        $this->chatStateRepository->saveStateForMinutes(
                            $chatId,
                            'dtp.waiting_confirmation',
                            30,
                            $userId,
                            [
                                'type' => 'dtp',
                                'request_id' => $dtpRequest['id'],
                            ]
                        );

                        $payload = $dtpRequest['payload'];
                        $phone = $this->normalizePhone($update['message']['body']['text']);
                        $contactId = $payload['contact']['id'] ?? $this->b24->getContactIdByPhone($phone);
                        $payload['contact']['id'] = $contactId;
                        $payload['contact']['phone'] = $phone;
                        $this->chatRequestRepository->setPayload($dtpRequest['id'], $payload);

                        $dtpInfo = $this->printDtpCard($payload);

                        return Bot::sendMessage(
                            'Подтвердите заявку:' . PHP_EOL . $dtpInfo,
                            [
                                'attachments' => [Keyboard::inlineKeyboard([
                                    [Keyboard::callback(
                                        $this->messages->get('button_label__correct'),
                                        'request_confirmed'
                                    )],
                                    [Keyboard::callback(
                                        $this->messages->get('button_label__cancel'),
                                        'menu'
                                    )],
                                ])],
                            ]
                        );
                    }
                }
            }

            if ($chatState == 'ask.waiting_question') {
                if (
                    isset($update['message']['body']['text'])
                    && $update['message']['body']['text'] !== ''
                ) {
                    if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'ask')) {
                        Bot::sendAction($chatId, 'typing_on');
                        $payload = $request['payload'];
                        $payload['question'] = trim($update['message']['body']['text']);
                        $this->chatRequestRepository->setPayload($request['id'], $payload);
                        $responsibleIds = [
                            'ДТП' => $this->b24->getDtpConsultantId($payload['contact']['id']),
                            'Юридические услуги' => 43,
                            'Экспертиза' => 43,
                        ];
                        $taskId = $this->b24->addTask([
                            'TITLE' => 'Новый вопрос в Max по теме ' . $payload['theme'],
                            'DESCRIPTION' => $payload['question'],
                            'DEADLINE' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                            'CREATED_BY' => $responsibleIds[$payload['theme']],
                            'RESPONSIBLE_ID' => $responsibleIds[$payload['theme']],
                            'UF_CRM_TASK' => ['C_124774'],
                        ]);
                        $this->chatRequestRepository->markSent($request['id'], $taskId, 'TASK');
                        $this->chatStateRepository->clearState($chatId);
                        return Bot::sendMessage('Ваш вопрос отправлен профильному специалисту. Ожидайте ответ');
                    }
                }
            }

            return null;
        });
    }

    private function registerActions(): void
    {
        $this->maxBot->action('menu', function() {
            $update = PHPMaxBot::$currentUpdate;

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__menu'),
                    'attachments' => [$this->getMenu()]
                ]
            ]);
        });

        $this->maxBot->action('request_dtp', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $userId = $update['message']['recipient']['user_id'];
            $contactId = $this->b24->getContactIdByMaxChatId($chatId);
            $requestId = $this->chatRequestRepository->create($chatId, 'dtp');
            $this->chatRequestRepository->setPayload($requestId, [
                'contact' => [
                    'id' => $contactId,
                ],
            ]);

            $this->chatStateRepository->clearState($chatId);
            $this->chatStateRepository->saveStateForMinutes(
                $chatId,
                'dtp.waiting_address',
                30,
                $userId,
                context: [
                    'type' => 'dtp',
                    'request_id' => $requestId,
                ]
            );

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__dtp_location'),
                    'attachments' => [Keyboard::inlineKeyboard([
                        [Keyboard::requestGeoLocation($this->messages->get('button_label__geo'))],
                        [Keyboard::callback(
                            $this->messages->get('button_label__manual_address'),
                            'manual_address'
                        )],
                        [Keyboard::callback($this->messages->get('button_label__back'), 'menu')],
                    ])]
                ]
            ]);
        });

        $this->maxBot->action('manual_address', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $userId = $update['message']['recipient']['user_id'];
            $requestId = $this->chatRequestRepository->create($chatId, 'dtp');

            $this->chatStateRepository->saveStateForMinutes(
                $chatId,
                'dtp.waiting_address',
                30,
                $userId,
                [
                    'type' => 'dtp',
                    'request_id' => $requestId,
                ]
            );

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__dtp_manual_address'),
                    'attachments' => [Keyboard::inlineKeyboard([
                        [Keyboard::callback($this->messages->get('button_label__back'), 'dtp')],
                    ])]
                ]
            ]);
        });

        $this->maxBot->action('address_confirmed', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $userId = $update['message']['recipient']['user_id'];
            $requestId = $this->chatRequestRepository->create($chatId, 'dtp');

            $this->chatStateRepository->saveStateForMinutes(
                $chatId,
                'dtp.waiting_victims',
                30,
                $userId,
                [
                    'type' => 'dtp',
                    'request_id' => $requestId,
                ]
            );

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__dtp_victims'),
                    'attachments' => [
                        Keyboard::inlineKeyboard([
                            [Keyboard::callback($this->messages->get('button_yes'), 'have_victims')],
                            [Keyboard::callback($this->messages->get('button_no'), 'no_victims')],
                        ])
                    ],
                ]
            ]);
        });

        $this->maxBot->action('have_victims', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $userId = $update['message']['recipient']['user_id'];

            if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'dtp')) {
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'dtp.waiting_contact',
                    30,
                    $userId,
                    [
                        'type' => 'dtp',
                        'request_id' => $request['id'],
                    ]
                );
                $payload = $request['payload'];
                $payload['victim'] = true;
                $this->chatRequestRepository->setPayload($request['id'], $payload);

                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => $this->messages->get('message__dtp_contact'),
                        'attachments' => [
                            Keyboard::inlineKeyboard([
                                [Keyboard::requestContact($this->messages->get('button_label__contact'))],
                                [Keyboard::callback(
                                    $this->messages->get('button_label__contact_manual'), 'name'
                                )],
                            ]),
                        ],
                    ]
                ]);
            }

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__something_wrong'),
                    'attachments' => [],
                ]
            ]);
        });

        $this->maxBot->action('no_victims', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $userId = $update['message']['recipient']['user_id'];

            if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'dtp')) {
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'dtp.waiting_contact',
                    30,
                    $userId,
                    [
                        'type' => 'dtp',
                        'request_id' => $request['id'],
                    ]
                );
                $payload = $request['payload'];
                $payload['victim'] = false;
                $this->chatRequestRepository->setPayload($request['id'], $payload);

                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => $this->messages->get('message__dtp_contact'),
                        'attachments' => [
                            Keyboard::inlineKeyboard([
                                [Keyboard::requestContact($this->messages->get('button_label__contact'))],
                                [Keyboard::callback(
                                    $this->messages->get('button_label__contact_manual'), 'name'
                                )],
                            ]),
                        ],
                    ]
                ]);
            }

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__something_wrong'),
                    'attachments' => [],
                ]
            ]);
        });

        $this->maxBot->action('name', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $userId = $update['message']['recipient']['user_id'];

            if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'dtp')) {
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'dtp.waiting_name',
                    30,
                    $userId,
                    [
                        'type' => 'dtp',
                        'request_id' => $request['id'],
                    ]
                );

                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => $this->messages->get('message__dtp_name'),
                        'attachments' => [],
                    ]
                ]);
            }

            return null;
        });

        $this->maxBot->action('request_confirmed', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $dtpRequest = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'dtp');
            $payload = $dtpRequest['payload'];
            $contactId = $payload['contact']['id'];

            if (!$contactId) {
                $contact = $payload['contact'] ?? [];
                $contactId = $this->b24->addContact([
                    'NAME' => $contact['name'] ?? '',
                    'PHONE' => [[
                        'VALUE' => $contact['phone'] ?? '',
                        'VALUE_TYPE' => 'WORK',
                    ]],
                ]);

                if ($contactId) {
                    $payload['contact']['id'] = $contactId;
                    $this->chatRequestRepository->setPayload($dtpRequest['id'], $payload);
                }
            }

            if ($dealId = $this->b24->addDeal([
                'TYPE_ID' => 'SALE',
                'STAGE_ID' => 'C14:NEW',
                'CATEGORY_ID' => 14,
                'CONTACT_IDS' => [$contactId],
                'SOURCE_ID' => '1|MAX',
                'COMMENTS' => $this->printDtpCard($dtpRequest['payload']),
                'ORIGIN_ID' => $dtpRequest['id'],
                'UF_CRM_1645478185' => $this->normalizeAddress($dtpRequest['payload']['location']['address']),
            ])) {
                $this->chatRequestRepository->markSent(
                    $dtpRequest['id'],
                    $dealId,
                    'DEAL'
                );

                $this->chatStateRepository->clearState($chatId);

                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => $this->messages->get('message__dtp_request_created'),
                        'attachments' => [],
                    ]
                ]);
            }

            return null;
        });

        $this->maxBot->action('status', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            Bot::sendAction($chatId, 'typing_on');
            if ($contactId = $this->b24->getContactIdByMaxChatId($chatId)) {
                $data = [
                    'message' => [
                        'text' => $this->b24->getDealsReportByContactId($contactId),
                        'attachments' => [],
                    ]
                ];
            } else {
                $this->chatStateRepository->clearState($chatId);
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'status.waiting_contact',
                    30,
                    $update['message']['recipient']['user_id'],
                    context: ['type' => 'status']
                );
                $data = [
                    'message' => [
                        'text' => '',
                        'attachments' => [Keyboard::inlineKeyboard([
                            [Keyboard::requestContact($this->messages->get('button_label__contact'))],
                        ])],
                    ],
                ];
            }
            return Bot::answerOnCallback($update['callback']['callback_id'], $data);
        });

        $this->maxBot->action('ask__start', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $requestId = $this->chatRequestRepository->create($chatId, 'ask');
            $contactId = $this->b24->getContactIdByMaxChatId($chatId);
            $this->chatStateRepository->clearState($chatId);
            Logger::info('[MaxService registerAction] ask__start', [
                'chat_id' => $chatId,
                'request_id' => $requestId,
            ]);
            if ($contactId) {
                $this->chatRequestRepository->setPayload($requestId, [
                    'contact' => [
                        'id' => $contactId,
                    ],
                ]);
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'ask.waiting_theme',
                    30,
                    $update['message']['recipient']['user_id'],
                    context: [
                        'type' => 'ask',
                        'request_id' => $requestId,
                    ]
                );
                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => 'По какой теме ваш вопрос?',
                        'attachments' => [Keyboard::inlineKeyboard([
                            [Keyboard::callback('ДТП', 'ask__dtp')],
                            [Keyboard::callback('Юридические услуги', 'ask__law')],
                            [Keyboard::callback('Экспертиза', 'ask__expertise')],
                        ])]
                    ]
                ]);
            } else {
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'ask.waiting_contact',
                    30,
                    $update['message']['recipient']['user_id'],
                    context: [
                        'type' => 'ask',
                        'request_id' => $requestId,
                    ]
                );
                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => 'Отправьте свои контактные данные',
                        'attachments' => [Keyboard::inlineKeyboard([
                            [Keyboard::requestContact($this->messages->get('button_label__contact'))],
                        ])],
                    ],
                ]);
            }
        });

        $this->maxBot->action('ask__dtp', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'ask')) {
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'ask.waiting_question',
                    30,
                    $update['message']['recipient']['user_id'],
                    [
                        'type' => 'ask',
                        'request_id' => $request['id'],
                    ]
                );
                $payload = $request['payload'];
                $payload['theme'] = 'ДТП';
                $this->chatRequestRepository->setPayload($request['id'], $payload);
                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => 'Отправьте в сообщении свой вопрос',
                        'attachments' => [],
                    ]
                ]);
            } else {
                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => 'Что-то пошло не так :/ начните заново',
                        'attachments' => [$this->getMenu()],
                    ]
                ]);
            }
        });

        $this->maxBot->action('ask__law', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'ask')) {
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'ask.waiting_question',
                    30,
                    $update['message']['recipient']['user_id'],
                    [
                        'type' => 'ask',
                        'request_id' => $request['id'],
                    ]
                );
                $payload = $request['payload'];
                $payload['theme'] = 'Юридические услуги';
                $this->chatRequestRepository->setPayload($request['id'], $payload);
                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => 'Отправьте в сообщении свой вопрос',
                        'attachments' => [],
                    ]
                ]);
            } else {
                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => 'Что-то пошло не так :/ начните заново',
                        'attachments' => [$this->getMenu()],
                    ]
                ]);
            }
        });

        $this->maxBot->action('ask__expertise', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            if ($request = $this->chatRequestRepository->getActiveByChatAndType($chatId, 'ask')) {
                $this->chatStateRepository->saveStateForMinutes(
                    $chatId,
                    'ask.waiting_question',
                    30,
                    $update['message']['recipient']['user_id'],
                    [
                        'type' => 'ask',
                        'request_id' => $request['id'],
                    ]
                );
                $payload = $request['payload'];
                $payload['theme'] = 'Экспертиза';
                $this->chatRequestRepository->setPayload($request['id'], $payload);
                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => 'Отправьте в сообщении свой вопрос',
                        'attachments' => [],
                    ]
                ]);
            } else {
                return Bot::answerOnCallback($update['callback']['callback_id'], [
                    'message' => [
                        'text' => 'Что-то пошло не так :/ начните заново',
                        'attachments' => [$this->getMenu()],
                    ]
                ]);
            }
        });
    }

    public function markDtpRequestInWork(int|string $dealId, string $commissarName, string $commissarPhone): array
    {
        try {
            $dtpRequest = $this->chatRequestRepository->getByDealIdAndType($dealId, 'dtp');
            $payload = $dtpRequest['payload'];
            $payload['commissar'] = $commissarName;
            $this->chatRequestRepository->markInWork($dtpRequest['id']);
            $this->chatRequestRepository->setPayload($dtpRequest['id'], $payload);

            $commissarPhone = $this->normalizePhone($commissarPhone);

            Bot::sendMessageToChat($dtpRequest['chat_id'],
                "Заявка № $dealId принята! Комиссар $commissarName, тел. $commissarPhone, "
                . "выедет к вам через ~25 минут. Ожидайте звонка."
            );

            return ['status' => 200, 'body' => 'OK'];
        } catch (ApiException $e) {
            Logger::error('Max webhook: API exception', [
                'message' => $e->getMessage(),
                'code'    => method_exists($e, 'getApiErrorCode') ? $e->getApiErrorCode() : null,
            ]);

            return ['status' => 500, 'body' => 'MAX API error'];
        } catch (MaxBotException $e) {
            Logger::error('Max webhook: library exception', [
                'message' => $e->getMessage(),
                'context' => method_exists($e, 'getContext') ? $e->getContext() : null,
            ]);

            return ['status' => 500, 'body' => 'Bot error'];
        } catch (Throwable $e) {
            Logger::error('Max webhook: unexpected exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return ['status' => 500, 'body' => 'Internal error'];
        }
    }

    private function getMenu(): array
    {
        return Keyboard::inlineKeyboard([
            [Keyboard::callback($this->messages->get('button_label__dtp'), 'request_dtp')],
            [Keyboard::callback($this->messages->get('button_label__status'), 'status')],
            [Keyboard::callback($this->messages->get('button_label__ask'), 'ask__start')],
        ]);
    }

    private function parseVCard(string $vcard): array
    {
        $result = [];

        $lines = preg_split('/\r\n|\n|\r/', $vcard);

        foreach ($lines as $line) {

            if (str_starts_with($line, 'TEL')) {
                [$key, $value] = explode(':', $line, 2);
                $result['phone'] = $value;
            }

            if (str_starts_with($line, 'FN')) {
                [$key, $value] = explode(':', $line, 2);
                $result['name'] = $value;
            }
        }

        return $result;
    }

    private function printDtpCard(array $card): string
    {
        try {
            return 'Адрес: ' . $card['location']['address'] . PHP_EOL
                . 'Имя: ' . $card['contact']['name'] . PHP_EOL
                . 'Телефон: ' . $card['contact']['phone'] . PHP_EOL
                . 'Есть пострадавшие: ' . ($card['victim'] ? 'да' : 'нет');
        } catch (Throwable $e) {
            return '';
        }
    }
    
    private function normalizeAddress(string $address): string
    {
        return mb_strtoupper(str_replace(
            ['г Иркутск', ' ул ', ', д '],
            ['г. Иркутск', ' ул. ', ' '],
            $address
        ));
    }

    private function normalizePhone(string $phone): string
    {
        return '+7' . substr(str_replace(['(', ')', '-', ' '], '', $phone), -10);
    }
}
