-- 提交幂等 & 内容去重迁移脚本
-- 用于已部署环境：防止网络中断后重试产生重复待审记录
-- 用法: mysql -u root -p community_board < database/migration_add_idempotency.sql

USE `community_board`;

-- 留言内容指纹（相同内容短时间去重兜底）
ALTER TABLE `messages`
    ADD COLUMN `content_fingerprint` CHAR(64) DEFAULT NULL COMMENT '内容指纹（去重）' AFTER `image`;
ALTER TABLE `messages`
    ADD INDEX `idx_fingerprint_status` (`content_fingerprint`, `status`);

-- 提交幂等令牌表
CREATE TABLE IF NOT EXISTS `submission_tokens` (
    `token` CHAR(64) NOT NULL COMMENT '提交幂等令牌',
    `message_id` INT UNSIGNED DEFAULT NULL COMMENT '对应留言ID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`token`),
    INDEX `idx_message_id` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言提交幂等令牌表';

-- 验证:
-- SHOW INDEX FROM messages WHERE Key_name = 'idx_fingerprint_status';
-- SHOW TABLES LIKE 'submission_tokens';
