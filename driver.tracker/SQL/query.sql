/*Add one more column in Role*/
UPDATE industries SET code = 'EDU' WHERE id = 1;
UPDATE industries SET code = 'LOG' WHERE id = 2;
UPDATE industries SET code = 'HLT' WHERE id = 3;
UPDATE industries SET code = 'IT'  WHERE id = 4;
UPDATE industries SET code = 'MFG' WHERE id = 5;
UPDATE industries SET code = 'TRN' WHERE id = 6;
UPDATE industries SET code = 'RTL' WHERE id = 7;
UPDATE industries SET code = 'FIN' WHERE id = 8;

RENAME TABLE `u613073349_school`.`fnc_locations` TO `u613073349_school`.`fin_locations`;
RENAME TABLE `u613073349_school`.`fin_user` TO `u613073349_school`.`fin_user`;
ALTER TABLE `van_route_history` CHANGE `driver_id` `edu_id` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
RENAME TABLE `u613073349_school`.`van_route_history` TO `u613073349_school`.`edu_route_history`;
CREATE TABLE fin_route_history LIKE edu_route_history;
ALTER TABLE `fin_route_history` CHANGE `edu_id` `fin_id` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
INSERT INTO `roles` (`id`, `role_name`, `prt`, `created_at`) VALUES (NULL, 'manager', '0', CURRENT_TIMESTAMP);
ALTER TABLE `edu_locations` CHANGE `organization_id` `organization_id` VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL;
ALTER TABLE `edu_user` CHANGE `notification_token` `notification_token` TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL;