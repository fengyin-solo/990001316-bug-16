-- 留言幂等提交迁移脚本
-- 作用：网络中断重试/重复点击时，同一提交凭证只产生一条待审记录
-- 执行：mysql -u root -p community_board < migration_add_client_token.sql

USE `community_board`;

ALTER TABLE `messages`
    ADD COLUMN `client_token` VARCHAR(64) DEFAULT NULL COMMENT '客户端幂等提交凭证' AFTER `status`;

-- 历史记录没有凭证，不做回填；仅对新提交去重
ALTER TABLE `messages`
    ADD UNIQUE KEY `uk_client_token` (`client_token`);
