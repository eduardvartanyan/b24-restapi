<?php
declare(strict_types=1);

use App\Http\Controllers\MaxController;
use App\Repositories\ChatRequestRepository;
use App\Repositories\ChatSourceRepository;
use App\Repositories\ChatStateRepository;
use App\Services\B24Service;
use App\Services\DaDataService;
use App\Services\MaxService;
use App\Support\MessageCatalog;
use Bitrix24\SDK\Core\Contracts\ApiClientInterface;
use Bitrix24\SDK\Core\Contracts\CoreInterface;
use Bitrix24\SDK\Core\Response\DTO\Pagination;
use Bitrix24\SDK\Core\Response\DTO\ResponseData;
use Bitrix24\SDK\Core\Response\DTO\Time;
use Bitrix24\SDK\Core\Response\Response;
use Bitrix24\SDK\Services\ServiceBuilder;

require_once __DIR__ . '/../vendor/autoload.php';

final class MaxTimelineResponse extends Response
{
    public function __construct(array $result)
    {
        $this->responseData = new ResponseData(
            $result,
            (new ReflectionClass(Time::class))->newInstanceWithoutConstructor(),
            new Pagination()
        );
    }

    public function getResponseData(): ResponseData
    {
        return $this->responseData;
    }
}

final class MaxTimelineCore implements CoreInterface
{
    public function __construct(public stdClass $state) {}

    public function call(string $apiMethod, array $parameters = []): Response
    {
        $this->state->calls[] = [$apiMethod, $parameters];
        return match ($apiMethod) {
            'crm.contact.list' => new MaxTimelineResponse($this->state->contacts),
            'crm.timeline.comment.add' => $this->state->failComment
                ? throw new RuntimeException('CRM unavailable')
                : new MaxTimelineResponse([$this->state->commentId]),
            default => throw new RuntimeException('Unexpected API method: ' . $apiMethod),
        };
    }

    public function getApiClient(): ApiClientInterface
    {
        throw new RuntimeException('Network access is not allowed in this test');
    }
}

final readonly class MaxTimelineService extends MaxService
{
    public function __construct(B24Service $b24, private stdClass $state)
    {
        $empty = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        parent::__construct(
            $b24,
            $empty(PHPMaxBot::class),
            $empty(ChatStateRepository::class),
            $empty(ChatRequestRepository::class),
            $empty(ChatSourceRepository::class),
            $empty(DaDataService::class),
            $empty(MessageCatalog::class),
            'test-token'
        );
    }

    public function sendMessage(
        string $message,
        int|string|null $chatId = null,
        int|string|null $userId = null,
        bool $notifyOnFailure = true
    ): array {
        $this->state->calls[] = ['max.send', $message, $chatId, $userId];
        return ['status' => $this->state->sendStatus, 'body' => 'test'];
    }

    public function sendNoticeMessage(string $message, int|string|null $chatId = null, int|string|null $userId = null): array
    {
        $this->state->calls[] = ['max.alert', $userId];
        return ['status' => 200, 'body' => 'OK'];
    }
}

function assertMaxTimeline(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$state = (object) [
    'calls' => [],
    'contacts' => [['ID' => '285382']],
    'commentId' => 123,
    'failComment' => false,
    'sendStatus' => 200,
];
$builder = (new ReflectionClass(ServiceBuilder::class))->newInstanceWithoutConstructor();
$builder->core = new MaxTimelineCore($state);
$service = new MaxTimelineService(new B24Service($builder), $state);
$message = "Первая строка\nВторая строка https://example.com";

$result = $service->sendB24Message("  {$message}  ", '245223929', '34369919');
assertMaxTimeline(true, $result['timeline_recorded']);
assertMaxTimeline(285382, $result['contact_id']);
assertMaxTimeline(['max.send', $message, '245223929', '34369919'], $state->calls[0]);
assertMaxTimeline(['crm.contact.list', [
    'filter' => ['=UF_CRM_1773132631' => '245223929'],
    'select' => ['ID'],
]], $state->calls[1]);
assertMaxTimeline(['crm.timeline.comment.add', ['fields' => [
    'ENTITY_ID' => 285382,
    'ENTITY_TYPE' => 'contact',
    'COMMENT' => "Отправлено сообщение в MAX:\n\n" . $message,
]]], $state->calls[2]);

$state->calls = [];
$service->sendB24Message($message, '', '34369919');
assertMaxTimeline(['=UF_CRM_MAXID_WZ' => '34369919'], $state->calls[1][1]['filter']);

$state->calls = [];
$state->sendStatus = 500;
assertMaxTimeline(500, $service->sendB24Message($message, '245223929')['status']);
assertMaxTimeline(1, count($state->calls));

$state->sendStatus = 200;
foreach ([[], [['ID' => '1'], ['ID' => '2']]] as $contacts) {
    $state->contacts = $contacts;
    $state->calls = [];
    $result = $service->sendB24Message($message, '245223929');
    assertMaxTimeline(200, $result['status']);
    assertMaxTimeline(false, $result['timeline_recorded']);
    assertMaxTimeline(['max.send', 'crm.contact.list', 'max.alert'], array_column($state->calls, 0));
}

$state->contacts = [['ID' => '285382']];
foreach (['failComment', 'commentId'] as $failure) {
    $state->failComment = $failure === 'failComment';
    $state->commentId = $failure === 'commentId' ? 0 : 123;
    $state->calls = [];
    $result = $service->sendB24Message($message, '245223929');
    assertMaxTimeline(200, $result['status']);
    assertMaxTimeline(false, $result['timeline_recorded']);
    assertMaxTimeline(['max.send', 'crm.contact.list', 'crm.timeline.comment.add', 'max.alert'], array_column($state->calls, 0));
}

$state->failComment = false;
$state->commentId = 123;
$controller = new MaxController($service);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['chatId' => '245223929', 'userId' => '34369919', 'message' => $message];
foreach ([false, true] as $registerInContact) {
    $state->calls = [];
    ob_start();
    $controller->handleB24MessageWebhook($registerInContact);
    $body = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    assertMaxTimeline(200, http_response_code());
    assertMaxTimeline($registerInContact, array_key_exists('timeline_recorded', $body));
    assertMaxTimeline($registerInContact ? 3 : 1, count($state->calls));
}

echo "MAX message timeline tests passed\n";
