CREATE TABLE chat_states (
     chat_id        BIGINT PRIMARY KEY,
     user_id        BIGINT,
     state          VARCHAR(100) NOT NULL,
     context        JSONB,
     created_at     TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
     updated_at     TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
     expires_at     TIMESTAMP WITH TIME ZONE
);