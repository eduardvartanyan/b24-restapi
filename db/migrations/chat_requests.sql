CREATE TABLE chat_requests (
      id                  BIGSERIAL PRIMARY KEY,
      chat_id             BIGINT NOT NULL,
      user_id             BIGINT,
      type                VARCHAR(50) NOT NULL,
      status              VARCHAR(30) NOT NULL DEFAULT 'draft',
      current_step        VARCHAR(100),
      crm_entity_type     VARCHAR(50),
      crm_entity_id       BIGINT,
      phone               VARCHAR(50),
      payload             JSONB,
      error_message       TEXT,
      created_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
      updated_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
      completed_at        TIMESTAMP WITH TIME ZONE,
      cancelled_at        TIMESTAMP WITH TIME ZONE
);
CREATE UNIQUE INDEX uniq_chat_request_type
    ON chat_requests (chat_id, type)
    WHERE status = 'draft';