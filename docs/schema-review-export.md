# SERBIS — database schema export

Database: `serbis_test_db`
Server: `10.4.32-MariaDB`
Generated: 2026-08-23 08:52:19
Source: live database, read-only queries.

Contents:

1. [Schema DDL](#1-schema-ddl)
2. [Row counts](#2-row-counts)
3. [Foreign key map](#3-foreign-key-map)
4. [Index inventory](#4-index-inventory)
5. [Distinct values for unconstrained string columns](#5-distinct-values-for-unconstrained-string-columns)
6. [Model / table mapping](#6-model--table-mapping)
7. [Environment note](#7-environment-note)

---

## 1. Schema DDL

`SHOW CREATE TABLE` output for all 24 tables, alphabetical.

### `cache`

```sql
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `cache_locks`

```sql
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `failed_jobs`

```sql
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `job_batches`

```sql
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `jobs`

```sql
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `migrations`

```sql
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `password_reset_tokens`

```sql
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `personal_access_tokens`

```sql
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `sessions`

```sql
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_barangay`

```sql
CREATE TABLE `tbl_barangay` (
  `barangay_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barangay_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`barangay_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_conduction_request_people`

```sql
CREATE TABLE `tbl_conduction_request_people` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conduction_request_id` bigint(20) unsigned NOT NULL,
  `role` enum('driver','passenger','relative') NOT NULL,
  `name` varchar(255) NOT NULL,
  `position` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tbl_conduction_request_people_conduction_request_id_foreign` (`conduction_request_id`),
  CONSTRAINT `tbl_conduction_request_people_conduction_request_id_foreign` FOREIGN KEY (`conduction_request_id`) REFERENCES `tbl_conduction_requests` (`conduction_request_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_conduction_requests`

```sql
CREATE TABLE `tbl_conduction_requests` (
  `conduction_request_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_name` varchar(255) NOT NULL,
  `patient_age` tinyint(3) unsigned DEFAULT NULL,
  `patient_address` varchar(255) NOT NULL,
  `patient_sex` enum('male','female') DEFAULT NULL,
  `patient_contact_number` varchar(255) NOT NULL,
  `vehicle` varchar(255) DEFAULT NULL,
  `medical_diagnosis` text NOT NULL,
  `plate_no` varchar(255) DEFAULT NULL,
  `origin` varchar(255) NOT NULL,
  `destination` varchar(255) NOT NULL,
  `departed_office_at` datetime DEFAULT NULL,
  `arrived_destination_at` datetime DEFAULT NULL,
  `departed_destination_at` datetime DEFAULT NULL,
  `returned_office_at` datetime DEFAULT NULL,
  `odometer_start` int(10) unsigned DEFAULT NULL,
  `odometer_end` int(10) unsigned DEFAULT NULL,
  `others` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`conduction_request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_equipment_borrowing`

```sql
CREATE TABLE `tbl_equipment_borrowing` (
  `borrow_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `equipment_id` bigint(20) unsigned NOT NULL,
  `quantity` int(11) NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('Pending','Approved','Released','Returned','Denied') NOT NULL DEFAULT 'Pending',
  `denial_reason` varchar(255) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`borrow_id`),
  KEY `tbl_equipment_borrowing_resident_id_foreign` (`resident_id`),
  KEY `tbl_equipment_borrowing_equipment_id_foreign` (`equipment_id`),
  KEY `tbl_equipment_borrowing_status_index` (`status`),
  CONSTRAINT `tbl_equipment_borrowing_equipment_id_foreign` FOREIGN KEY (`equipment_id`) REFERENCES `tbl_equipments` (`equipment_id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_equipment_borrowing_resident_id_foreign` FOREIGN KEY (`resident_id`) REFERENCES `tbl_residents` (`resident_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_equipments`

```sql
CREATE TABLE `tbl_equipments` (
  `equipment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_name` varchar(255) NOT NULL,
  `total_quantity` int(11) NOT NULL,
  `available_quantity` int(11) NOT NULL,
  `status` enum('Available','Unavailable') NOT NULL DEFAULT 'Available',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`equipment_id`),
  UNIQUE KEY `tbl_equipments_item_name_unique` (`item_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_info_materials`

```sql
CREATE TABLE `tbl_info_materials` (
  `files_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uploader_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL COMMENT 'Size in bytes',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`files_id`),
  KEY `tbl_info_materials_uploader_id_foreign` (`uploader_id`),
  CONSTRAINT `tbl_info_materials_uploader_id_foreign` FOREIGN KEY (`uploader_id`) REFERENCES `tbl_user` (`admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_recipients`

```sql
CREATE TABLE `tbl_recipients` (
  `recipient_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sms_log_id` bigint(20) unsigned NOT NULL,
  `resident_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`recipient_id`),
  KEY `tbl_recipients_sms_log_id_foreign` (`sms_log_id`),
  KEY `tbl_recipients_resident_id_foreign` (`resident_id`),
  CONSTRAINT `tbl_recipients_resident_id_foreign` FOREIGN KEY (`resident_id`) REFERENCES `tbl_residents` (`resident_id`),
  CONSTRAINT `tbl_recipients_sms_log_id_foreign` FOREIGN KEY (`sms_log_id`) REFERENCES `tbl_sms_logs` (`sms_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_residents`

```sql
CREATE TABLE `tbl_residents` (
  `resident_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barangay_id` bigint(20) unsigned NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) NOT NULL,
  `phone_number` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `sms_opt_in` tinyint(1) NOT NULL DEFAULT 1,
  `email_address` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `verification_code` varchar(255) DEFAULT NULL,
  `verification_code_expires_at` timestamp NULL DEFAULT NULL,
  `verification_code_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`resident_id`),
  UNIQUE KEY `tbl_residents_email_address_unique` (`email_address`),
  KEY `tbl_residents_barangay_id_foreign` (`barangay_id`),
  CONSTRAINT `tbl_residents_barangay_id_foreign` FOREIGN KEY (`barangay_id`) REFERENCES `tbl_barangay` (`barangay_id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_service_request`

```sql
CREATE TABLE `tbl_service_request` (
  `request_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned DEFAULT NULL,
  `walk_in_name` varchar(255) DEFAULT NULL,
  `walk_in_contact_number` varchar(255) DEFAULT NULL,
  `service_id` bigint(20) unsigned NOT NULL,
  `vehicle_id` bigint(20) unsigned DEFAULT NULL,
  `processed_by` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `valid_id` varchar(255) DEFAULT NULL,
  `site_photo` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `tbl_service_request_resident_id_foreign` (`resident_id`),
  KEY `tbl_service_request_service_id_foreign` (`service_id`),
  KEY `tbl_service_request_processed_by_foreign` (`processed_by`),
  KEY `tbl_service_request_vehicle_id_foreign` (`vehicle_id`),
  KEY `tbl_service_request_status_index` (`status`),
  KEY `tbl_service_request_status_created_at_index` (`status`,`created_at`),
  KEY `tbl_service_request_created_at_index` (`created_at`),
  CONSTRAINT `tbl_service_request_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `tbl_user` (`admin_id`),
  CONSTRAINT `tbl_service_request_resident_id_foreign` FOREIGN KEY (`resident_id`) REFERENCES `tbl_residents` (`resident_id`),
  CONSTRAINT `tbl_service_request_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `tbl_services` (`service_id`),
  CONSTRAINT `tbl_service_request_vehicle_id_foreign` FOREIGN KEY (`vehicle_id`) REFERENCES `tbl_vehicles` (`vehicle_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_service_translations`

```sql
CREATE TABLE `tbl_service_translations` (
  `service_translation_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `locale` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`service_translation_id`),
  UNIQUE KEY `tbl_service_translations_service_id_locale_unique` (`service_id`,`locale`),
  CONSTRAINT `tbl_service_translations_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `tbl_services` (`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_services`

```sql
CREATE TABLE `tbl_services` (
  `service_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`service_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_sms_logs`

```sql
CREATE TABLE `tbl_sms_logs` (
  `sms_log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` bigint(20) unsigned NOT NULL,
  `target_area_id` bigint(20) unsigned NOT NULL,
  `disaster_id` bigint(20) unsigned DEFAULT NULL,
  `api_job_id` varchar(255) DEFAULT NULL,
  `message_body` text NOT NULL,
  `status` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`sms_log_id`),
  KEY `tbl_sms_logs_sender_id_foreign` (`sender_id`),
  KEY `tbl_sms_logs_target_area_id_foreign` (`target_area_id`),
  KEY `tbl_sms_logs_disaster_id_foreign` (`disaster_id`),
  CONSTRAINT `tbl_sms_logs_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `tbl_user` (`admin_id`),
  CONSTRAINT `tbl_sms_logs_target_area_id_foreign` FOREIGN KEY (`target_area_id`) REFERENCES `tbl_barangay` (`barangay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_system_logs`

```sql
CREATE TABLE `tbl_system_logs` (
  `log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `resident_id` bigint(20) unsigned DEFAULT NULL,
  `action_type` varchar(255) NOT NULL,
  `auditable_type` varchar(255) NOT NULL,
  `auditable_id` bigint(20) unsigned NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `tbl_system_logs_resident_id_foreign` (`resident_id`),
  KEY `tbl_system_logs_admin_id_foreign` (`admin_id`),
  CONSTRAINT `tbl_system_logs_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `tbl_user` (`admin_id`),
  CONSTRAINT `tbl_system_logs_resident_id_foreign` FOREIGN KEY (`resident_id`) REFERENCES `tbl_residents` (`resident_id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_user`

```sql
CREATE TABLE `tbl_user` (
  `admin_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'Active',
  `email_address` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `tbl_user_email_address_unique` (`email_address`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `tbl_vehicles`

```sql
CREATE TABLE `tbl_vehicles` (
  `vehicle_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `unit_identifier` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `specification` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'Available',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`vehicle_id`),
  UNIQUE KEY `tbl_vehicles_unit_identifier_unique` (`unit_identifier`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

---

## 2. Row counts

Exact `COUNT(*)` per table. Not `information_schema` estimates.

| Table | Rows |
| --- | ---: |
| `cache` | 28 |
| `cache_locks` | 0 |
| `failed_jobs` | 0 |
| `job_batches` | 0 |
| `jobs` | 0 |
| `migrations` | 39 |
| `password_reset_tokens` | 0 |
| `personal_access_tokens` | 27 |
| `sessions` | 5 |
| `tbl_barangay` | 3 |
| `tbl_conduction_request_people` | 0 |
| `tbl_conduction_requests` | 0 |
| `tbl_equipment_borrowing` | 6 |
| `tbl_equipments` | 7 |
| `tbl_info_materials` | 1 |
| `tbl_recipients` | 0 |
| `tbl_residents` | 22 |
| `tbl_service_request` | 39 |
| `tbl_service_translations` | 20 |
| `tbl_services` | 10 |
| `tbl_sms_logs` | 0 |
| `tbl_system_logs` | 52 |
| `tbl_user` | 1 |
| `tbl_vehicles` | 14 |

---

## 3. Foreign key map

From `information_schema.KEY_COLUMN_USAGE` joined to `information_schema.REFERENTIAL_CONSTRAINTS`.

| Constraint | Source | Target | ON DELETE | ON UPDATE |
| --- | --- | --- | --- | --- |
| `tbl_conduction_request_people_conduction_request_id_foreign` | `tbl_conduction_request_people.conduction_request_id` | `tbl_conduction_requests.conduction_request_id` | CASCADE | RESTRICT |
| `tbl_equipment_borrowing_equipment_id_foreign` | `tbl_equipment_borrowing.equipment_id` | `tbl_equipments.equipment_id` | CASCADE | RESTRICT |
| `tbl_equipment_borrowing_resident_id_foreign` | `tbl_equipment_borrowing.resident_id` | `tbl_residents.resident_id` | CASCADE | RESTRICT |
| `tbl_info_materials_uploader_id_foreign` | `tbl_info_materials.uploader_id` | `tbl_user.admin_id` | RESTRICT | RESTRICT |
| `tbl_recipients_resident_id_foreign` | `tbl_recipients.resident_id` | `tbl_residents.resident_id` | RESTRICT | RESTRICT |
| `tbl_recipients_sms_log_id_foreign` | `tbl_recipients.sms_log_id` | `tbl_sms_logs.sms_log_id` | RESTRICT | RESTRICT |
| `tbl_residents_barangay_id_foreign` | `tbl_residents.barangay_id` | `tbl_barangay.barangay_id` | RESTRICT | RESTRICT |
| `tbl_service_request_processed_by_foreign` | `tbl_service_request.processed_by` | `tbl_user.admin_id` | RESTRICT | RESTRICT |
| `tbl_service_request_resident_id_foreign` | `tbl_service_request.resident_id` | `tbl_residents.resident_id` | RESTRICT | RESTRICT |
| `tbl_service_request_service_id_foreign` | `tbl_service_request.service_id` | `tbl_services.service_id` | RESTRICT | RESTRICT |
| `tbl_service_request_vehicle_id_foreign` | `tbl_service_request.vehicle_id` | `tbl_vehicles.vehicle_id` | SET NULL | RESTRICT |
| `tbl_service_translations_service_id_foreign` | `tbl_service_translations.service_id` | `tbl_services.service_id` | CASCADE | RESTRICT |
| `tbl_sms_logs_sender_id_foreign` | `tbl_sms_logs.sender_id` | `tbl_user.admin_id` | RESTRICT | RESTRICT |
| `tbl_sms_logs_target_area_id_foreign` | `tbl_sms_logs.target_area_id` | `tbl_barangay.barangay_id` | RESTRICT | RESTRICT |
| `tbl_system_logs_admin_id_foreign` | `tbl_system_logs.admin_id` | `tbl_user.admin_id` | RESTRICT | RESTRICT |
| `tbl_system_logs_resident_id_foreign` | `tbl_system_logs.resident_id` | `tbl_residents.resident_id` | RESTRICT | RESTRICT |

---

## 4. Index inventory

From `information_schema.STATISTICS`. Columns listed in `SEQ_IN_INDEX` order. Cardinality is the value the server currently reports and is an estimate maintained by the storage engine.

| Table | Index | Columns | Unique | Cardinality | Type |
| --- | --- | --- | --- | ---: | --- |
| `cache` | `cache_expiration_index` | `expiration` | non-unique | 28 | BTREE |
| `cache` | `PRIMARY` | `key` | UNIQUE | 28 | BTREE |
| `cache_locks` | `cache_locks_expiration_index` | `expiration` | non-unique | 0 | BTREE |
| `cache_locks` | `PRIMARY` | `key` | UNIQUE | 0 | BTREE |
| `failed_jobs` | `failed_jobs_connection_queue_failed_at_index` | `connection, queue, failed_at` | non-unique | 0 | BTREE |
| `failed_jobs` | `failed_jobs_uuid_unique` | `uuid` | UNIQUE | 0 | BTREE |
| `failed_jobs` | `PRIMARY` | `id` | UNIQUE | 0 | BTREE |
| `jobs` | `jobs_queue_index` | `queue` | non-unique | 0 | BTREE |
| `jobs` | `PRIMARY` | `id` | UNIQUE | 0 | BTREE |
| `job_batches` | `PRIMARY` | `id` | UNIQUE | 0 | BTREE |
| `migrations` | `PRIMARY` | `id` | UNIQUE | 38 | BTREE |
| `password_reset_tokens` | `PRIMARY` | `email` | UNIQUE | 0 | BTREE |
| `personal_access_tokens` | `personal_access_tokens_expires_at_index` | `expires_at` | non-unique | 26 | BTREE |
| `personal_access_tokens` | `personal_access_tokens_tokenable_type_tokenable_id_index` | `tokenable_type, tokenable_id` | non-unique | 4 | BTREE |
| `personal_access_tokens` | `personal_access_tokens_token_unique` | `token` | UNIQUE | 26 | BTREE |
| `personal_access_tokens` | `PRIMARY` | `id` | UNIQUE | 26 | BTREE |
| `sessions` | `PRIMARY` | `id` | UNIQUE | 0 | BTREE |
| `sessions` | `sessions_last_activity_index` | `last_activity` | non-unique | 0 | BTREE |
| `sessions` | `sessions_user_id_index` | `user_id` | non-unique | 0 | BTREE |
| `tbl_barangay` | `PRIMARY` | `barangay_id` | UNIQUE | 3 | BTREE |
| `tbl_conduction_requests` | `PRIMARY` | `conduction_request_id` | UNIQUE | 0 | BTREE |
| `tbl_conduction_request_people` | `PRIMARY` | `id` | UNIQUE | 0 | BTREE |
| `tbl_conduction_request_people` | `tbl_conduction_request_people_conduction_request_id_foreign` | `conduction_request_id` | non-unique | 0 | BTREE |
| `tbl_equipments` | `PRIMARY` | `equipment_id` | UNIQUE | 7 | BTREE |
| `tbl_equipments` | `tbl_equipments_item_name_unique` | `item_name` | UNIQUE | 7 | BTREE |
| `tbl_equipment_borrowing` | `PRIMARY` | `borrow_id` | UNIQUE | 6 | BTREE |
| `tbl_equipment_borrowing` | `tbl_equipment_borrowing_equipment_id_foreign` | `equipment_id` | non-unique | 6 | BTREE |
| `tbl_equipment_borrowing` | `tbl_equipment_borrowing_resident_id_foreign` | `resident_id` | non-unique | 6 | BTREE |
| `tbl_equipment_borrowing` | `tbl_equipment_borrowing_status_index` | `status` | non-unique | 6 | BTREE |
| `tbl_info_materials` | `PRIMARY` | `files_id` | UNIQUE | 1 | BTREE |
| `tbl_info_materials` | `tbl_info_materials_uploader_id_foreign` | `uploader_id` | non-unique | 1 | BTREE |
| `tbl_recipients` | `PRIMARY` | `recipient_id` | UNIQUE | 0 | BTREE |
| `tbl_recipients` | `tbl_recipients_resident_id_foreign` | `resident_id` | non-unique | 0 | BTREE |
| `tbl_recipients` | `tbl_recipients_sms_log_id_foreign` | `sms_log_id` | non-unique | 0 | BTREE |
| `tbl_residents` | `PRIMARY` | `resident_id` | UNIQUE | 22 | BTREE |
| `tbl_residents` | `tbl_residents_barangay_id_foreign` | `barangay_id` | non-unique | 7 | BTREE |
| `tbl_residents` | `tbl_residents_email_address_unique` | `email_address` | UNIQUE | 22 | BTREE |
| `tbl_services` | `PRIMARY` | `service_id` | UNIQUE | 10 | BTREE |
| `tbl_service_request` | `PRIMARY` | `request_id` | UNIQUE | 38 | BTREE |
| `tbl_service_request` | `tbl_service_request_created_at_index` | `created_at` | non-unique | 38 | BTREE |
| `tbl_service_request` | `tbl_service_request_processed_by_foreign` | `processed_by` | non-unique | 2 | BTREE |
| `tbl_service_request` | `tbl_service_request_resident_id_foreign` | `resident_id` | non-unique | 38 | BTREE |
| `tbl_service_request` | `tbl_service_request_service_id_foreign` | `service_id` | non-unique | 19 | BTREE |
| `tbl_service_request` | `tbl_service_request_status_created_at_index` | `status, created_at` | non-unique | 38 | BTREE |
| `tbl_service_request` | `tbl_service_request_status_index` | `status` | non-unique | 9 | BTREE |
| `tbl_service_request` | `tbl_service_request_vehicle_id_foreign` | `vehicle_id` | non-unique | 2 | BTREE |
| `tbl_service_translations` | `PRIMARY` | `service_translation_id` | UNIQUE | 20 | BTREE |
| `tbl_service_translations` | `tbl_service_translations_service_id_locale_unique` | `service_id, locale` | UNIQUE | 20 | BTREE |
| `tbl_sms_logs` | `PRIMARY` | `sms_log_id` | UNIQUE | 0 | BTREE |
| `tbl_sms_logs` | `tbl_sms_logs_disaster_id_foreign` | `disaster_id` | non-unique | 0 | BTREE |
| `tbl_sms_logs` | `tbl_sms_logs_sender_id_foreign` | `sender_id` | non-unique | 0 | BTREE |
| `tbl_sms_logs` | `tbl_sms_logs_target_area_id_foreign` | `target_area_id` | non-unique | 0 | BTREE |
| `tbl_system_logs` | `PRIMARY` | `log_id` | UNIQUE | 54 | BTREE |
| `tbl_system_logs` | `tbl_system_logs_admin_id_foreign` | `admin_id` | non-unique | 4 | BTREE |
| `tbl_system_logs` | `tbl_system_logs_resident_id_foreign` | `resident_id` | non-unique | 4 | BTREE |
| `tbl_user` | `PRIMARY` | `admin_id` | UNIQUE | 0 | BTREE |
| `tbl_user` | `tbl_user_email_address_unique` | `email_address` | UNIQUE | 0 | BTREE |
| `tbl_vehicles` | `PRIMARY` | `vehicle_id` | UNIQUE | 14 | BTREE |
| `tbl_vehicles` | `tbl_vehicles_unit_identifier_unique` | `unit_identifier` | UNIQUE | 14 | BTREE |

---

## 5. Distinct values for unconstrained string columns

Scope: `varchar`/`text` columns holding a small value set. `enum` columns are excluded because the allowed set is already fixed in the DDL; they are listed at the end of this section for reference. Personal data, credentials, tokens, file paths, free-text prose and framework serialisation blobs are excluded and listed at the end as well.

Counts are `COUNT(*)` per distinct value. `<NULL>` denotes SQL NULL.

### `failed_jobs.connection`

_Table is empty; the column holds no values._

### `failed_jobs.queue`

_Table is empty; the column holds no values._

### `jobs.queue`

_Table is empty; the column holds no values._

### `personal_access_tokens.tokenable_type`

| Value | Rows |
| --- | ---: |
| `App\Models\Resident` | 5 |
| `App\Models\User` | 22 |

### `personal_access_tokens.abilities`

| Value | Rows |
| --- | ---: |
| `["*"]` | 27 |

### `tbl_barangay.barangay_name`

| Value | Rows |
| --- | ---: |
| `San Antonio Ugad` | 1 |
| `San Fabian` | 1 |
| `San Miguel` | 1 |

### `tbl_equipments.item_name`

| Value | Rows |
| --- | ---: |
| `First Aid Kit` | 1 |
| `Generator` | 1 |
| `Megaphone` | 1 |
| `Oxygen Tank` | 1 |
| `Rescue Tools` | 1 |
| `Stretcher` | 1 |
| `Wheelchair` | 1 |

### `tbl_info_materials.file_type`

| Value | Rows |
| --- | ---: |
| `jpg` | 1 |

### `tbl_recipients.status`

_Table is empty; the column holds no values._

### `tbl_residents.status`

| Value | Rows |
| --- | ---: |
| `Active` | 7 |
| `Inactive` | 15 |

### `tbl_service_request.status`

| Value | Rows |
| --- | ---: |
| `Cancelled` | 1 |
| `Pending` | 19 |
| `Resolved` | 10 |
| `Responding` | 9 |

### `tbl_service_translations.locale`

| Value | Rows |
| --- | ---: |
| `en` | 10 |
| `fil` | 10 |

### `tbl_services.service_name`

| Value | Rows |
| --- | ---: |
| `Ambulance/Medical Response` | 1 |
| `Animal Rescue` | 1 |
| `Debris Removal` | 1 |
| `Fire Rescue` | 1 |
| `Flood Evacuation` | 1 |
| `Power Line Repair` | 1 |
| `Relief Goods Distribution` | 1 |
| `Road Clearing` | 1 |
| `Sandbagging` | 1 |
| `Search and Rescue` | 1 |

### `tbl_sms_logs.status`

_Table is empty; the column holds no values._

### `tbl_system_logs.action_type`

| Value | Rows |
| --- | ---: |
| `created` | 43 |
| `deleted` | 3 |
| `updated` | 6 |

### `tbl_system_logs.auditable_type`

| Value | Rows |
| --- | ---: |
| `App\Models\EquipmentBorrowing` | 2 |
| `App\Models\InfoMaterial` | 16 |
| `App\Models\Resident` | 6 |
| `App\Models\ServiceRequest` | 10 |
| `App\Models\Vehicle` | 18 |

### `tbl_user.role`

| Value | Rows |
| --- | ---: |
| `Admin` | 1 |

### `tbl_user.status`

| Value | Rows |
| --- | ---: |
| `Active` | 1 |

### `tbl_vehicles.type`

| Value | Rows |
| --- | ---: |
| `Ambulance` | 4 |
| `Boat` | 6 |
| `Fire Truck` | 2 |
| `Rescue Vehicle` | 2 |

### `tbl_vehicles.specification`

| Value | Rows |
| --- | ---: |
| `<NULL>` | 2 |
| `Baracuda Boat` | 2 |
| `Fiber Glass` | 1 |
| `Pickup` | 2 |
| `Portable Boat` | 2 |
| `PTU` | 2 |
| `TYPE I` | 2 |
| `Unsinkable` | 1 |

### `tbl_vehicles.status`

| Value | Rows |
| --- | ---: |
| `Available` | 14 |

### Enum columns (excluded above; set fixed in the DDL)

| Column | Declared type |
| --- | --- |
| `tbl_conduction_requests.patient_sex` | `enum('male','female')` |
| `tbl_conduction_request_people.role` | `enum('driver','passenger','relative')` |
| `tbl_equipments.status` | `enum('Available','Unavailable')` |
| `tbl_equipment_borrowing.status` | `enum('Pending','Approved','Released','Returned','Denied')` |

### String columns excluded from this section

Personal data: `tbl_residents.first_name`, `.middle_name`, `.last_name`, `.phone_number`, `.email_address`, `.photo`; `tbl_user.first_name`, `.last_name`, `.email_address`; `tbl_conduction_requests.patient_name`, `.patient_address`, `.patient_contact_number`, `.medical_diagnosis`; `tbl_conduction_request_people.name`; `tbl_service_request.walk_in_name`, `.walk_in_contact_number`.

Credentials and tokens: `tbl_residents.password`, `.verification_code`; `tbl_user.password`; `password_reset_tokens.email`, `.token`; `personal_access_tokens.token`, `.name`; `sessions.id`, `.ip_address`, `.user_agent`; `tbl_system_logs.ip_address`, `.user_agent`.

File paths: `tbl_info_materials.title`, `.file_path`; `tbl_service_request.valid_id`, `.site_photo`.

Free-text prose: `tbl_services.description`; `tbl_service_translations.name`, `.description`; `tbl_service_request.description`, `.remarks`; `tbl_equipment_borrowing.denial_reason`; `tbl_conduction_requests.vehicle`, `.plate_no`, `.origin`, `.destination`, `.others`; `tbl_sms_logs.message_body`, `.api_job_id`.

Serialisation blobs and per-row identifiers: `cache.key`, `.value`; `cache_locks.key`, `.owner`; `failed_jobs.uuid`, `.payload`, `.exception`; `jobs.payload`; `job_batches.id`, `.name`, `.failed_job_ids`, `.options`; `sessions.payload`; `migrations.migration`; `tbl_system_logs.old_values`, `.new_values`; `tbl_vehicles.unit_identifier`.

---

## 6. Model / table mapping

Every class in `app/Models/`. Table and primary key are read from the booted Eloquent instance (`getTable()`, `getKeyName()`), so they reflect whichever declaration style the model uses — `protected $table`, or the Laravel 12 `#[Table]` attribute.

Audit trait = `App\Traits\TracksHistory`, which writes to `tbl_system_logs`.

| Model | Table | Primary key | Audit trait | Traits declared on the class | Relationships |
| --- | --- | --- | --- | --- | --- |
| `Barangay` | `tbl_barangay` | `barangay_id` | yes | HasFactory, TracksHistory | residents(): HasMany -> Resident |
| `ConductionRequest` | `tbl_conduction_requests` | `conduction_request_id` | yes | TracksHistory | people(): HasMany -> ConductionRequestPerson<br>drivers(): HasMany -> ConductionRequestPerson<br>authorizedPassengers(): HasMany -> ConductionRequestPerson<br>patientRelatives(): HasMany -> ConductionRequestPerson |
| `ConductionRequestPerson` | `tbl_conduction_request_people` | `id` | no | (none) | conductionRequest(): BelongsTo -> ConductionRequest |
| `Equipment` | `tbl_equipments` | `equipment_id` | yes | TracksHistory | (none) |
| `EquipmentBorrowing` | `tbl_equipment_borrowing` | `borrow_id` | yes | TracksHistory | resident(): BelongsTo -> Resident<br>equipment(): BelongsTo -> Equipment |
| `InfoMaterial` | `tbl_info_materials` | `files_id` | yes | TracksHistory | (none) |
| `Recipient` | `tbl_recipients` | `recipient_id` | no | HasFactory | smsLog(): BelongsTo -> SmsLog<br>resident(): BelongsTo -> Resident |
| `Resident` | `tbl_residents` | `resident_id` | yes | HasApiTokens, HasFactory, TracksHistory | barangay(): BelongsTo -> Barangay |
| `Service` | `tbl_services` | `service_id` | yes | HasFactory, TracksHistory | translations(): HasMany -> ServiceTranslation |
| `ServiceRequest` | `tbl_service_request` | `request_id` | yes | HasFactory, TracksHistory | resident(): BelongsTo -> Resident<br>service(): BelongsTo -> Service<br>admin(): BelongsTo -> User<br>vehicle(): BelongsTo -> Vehicle |
| `ServiceTranslation` | `tbl_service_translations` | `service_translation_id` | no | HasFactory | service(): BelongsTo -> Service |
| `SmsLog` | `tbl_sms_logs` | `sms_log_id` | no | HasFactory | barangay(): BelongsTo -> Barangay<br>sender(): BelongsTo -> User<br>recipients(): HasMany -> Recipient |
| `SystemLog` | `tbl_system_logs` | `log_id` | no | (none) | admin(): BelongsTo -> User<br>resident(): BelongsTo -> Resident |
| `User` | `tbl_user` | `admin_id` | yes | HasApiTokens, HasFactory, Notifiable, TracksHistory | (none) |
| `Vehicle` | `tbl_vehicles` | `vehicle_id` | yes | HasFactory, TracksHistory | serviceRequests(): HasMany -> ServiceRequest |

Relationship rows list every public zero-argument method on the model class whose declared return type is an Eloquent relation, resolved to the related model.

### Models whose table does not exist

None. All 15 model tables are present in the database.

### Tables with no model

| Table | Origin |
| --- | --- |
| `cache` | Laravel framework |
| `cache_locks` | Laravel framework |
| `failed_jobs` | Laravel framework |
| `job_batches` | Laravel framework |
| `jobs` | Laravel framework |
| `migrations` | Laravel framework |
| `password_reset_tokens` | Laravel framework |
| `personal_access_tokens` | Laravel Sanctum |
| `sessions` | Laravel framework |

15 model tables + 9 unmapped tables = 24 tables.

---

## 7. Environment note

| | |
| --- | --- |
| Server version string | `10.4.32-MariaDB` |
| Product | MariaDB 10.4.32 |
| Database | `serbis_test_db` |
| Storage engine | `InnoDB` on all 24 tables |
| Character set | `utf8mb4` on all 24 tables |
| Collation | `utf8mb4_unicode_ci` on all 24 tables |

The schema is produced by 39 Laravel migrations. The DDL in section 1 is what MariaDB 10.4 emits for them; the same migrations run against MySQL 8.4 produce the differences below.

### 7.1 JSON / LONGTEXT aliasing

MariaDB 10.4 has no native `JSON` type. `JSON` is an alias for `LONGTEXT`, and the server adds a `json_valid()` CHECK constraint. Two columns are declared `$table->json(...)` in the migrations and land as `longtext`:

| Column | Migration declaration | MariaDB 10.4.32 (live) | MySQL 8.4 |
| --- | --- | --- | --- |
| `tbl_system_logs.old_values` | `$table->json('old_values')->nullable()` (`database/migrations/2026_06_27_093419_update_tbl_system_logs_table.php:19`) | ``longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)) `` | `json DEFAULT NULL` |
| `tbl_system_logs.new_values` | `$table->json('new_values')->nullable()` (same migration, line 20) | ``longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)) `` | `json DEFAULT NULL` |

The other `longtext`/`mediumtext` columns are declared as text in the migrations and are unaffected: `cache.value`, `job_batches.options` (`mediumText`); `failed_jobs.payload`, `failed_jobs.exception`, `jobs.payload`, `job_batches.failed_job_ids`, `sessions.payload` (`longText`).

### 7.2 Integer display widths

MariaDB 10.4 emits a display width for every integer type. MySQL 8.0.19 deprecated display width for integer types and MySQL 8.4 omits it from `SHOW CREATE TABLE`, so each of the following renders without the parenthesised number (`bigint(20) unsigned` becomes `bigint unsigned`). Storage size and value range are identical; the difference is textual.

| Column type in the live DDL | Occurrences | Rendered under MySQL 8.4 |
| --- | ---: | --- |
| `bigint(20) unsigned` | 38 | `bigint unsigned` |
| `int(11)` | 12 | `int` |
| `int(10) unsigned` | 6 | `int unsigned` |
| `bigint(20)` | 2 | `bigint` |
| `tinyint(3) unsigned` | 2 | `tinyint unsigned` |
| `smallint(5) unsigned` | 1 | `smallint unsigned` |

`tbl_residents.sms_opt_in` is `tinyint(1)` and is the one integer column that keeps its display width under MySQL 8.4: `tinyint(1)` is the boolean idiom and is preserved.

The 61 affected columns:

| Table | Column | Live type |
| --- | --- | --- |
| `cache` | `expiration` | `bigint(20)` |
| `cache_locks` | `expiration` | `bigint(20)` |
| `failed_jobs` | `id` | `bigint(20) unsigned` |
| `jobs` | `id` | `bigint(20) unsigned` |
| `jobs` | `attempts` | `smallint(5) unsigned` |
| `jobs` | `reserved_at` | `int(10) unsigned` |
| `jobs` | `available_at` | `int(10) unsigned` |
| `jobs` | `created_at` | `int(10) unsigned` |
| `job_batches` | `total_jobs` | `int(11)` |
| `job_batches` | `pending_jobs` | `int(11)` |
| `job_batches` | `failed_jobs` | `int(11)` |
| `job_batches` | `cancelled_at` | `int(11)` |
| `job_batches` | `created_at` | `int(11)` |
| `job_batches` | `finished_at` | `int(11)` |
| `migrations` | `id` | `int(10) unsigned` |
| `migrations` | `batch` | `int(11)` |
| `personal_access_tokens` | `id` | `bigint(20) unsigned` |
| `personal_access_tokens` | `tokenable_id` | `bigint(20) unsigned` |
| `sessions` | `user_id` | `bigint(20) unsigned` |
| `sessions` | `last_activity` | `int(11)` |
| `tbl_barangay` | `barangay_id` | `bigint(20) unsigned` |
| `tbl_conduction_requests` | `conduction_request_id` | `bigint(20) unsigned` |
| `tbl_conduction_requests` | `patient_age` | `tinyint(3) unsigned` |
| `tbl_conduction_requests` | `odometer_start` | `int(10) unsigned` |
| `tbl_conduction_requests` | `odometer_end` | `int(10) unsigned` |
| `tbl_conduction_request_people` | `id` | `bigint(20) unsigned` |
| `tbl_conduction_request_people` | `conduction_request_id` | `bigint(20) unsigned` |
| `tbl_conduction_request_people` | `position` | `tinyint(3) unsigned` |
| `tbl_equipments` | `equipment_id` | `bigint(20) unsigned` |
| `tbl_equipments` | `total_quantity` | `int(11)` |
| `tbl_equipments` | `available_quantity` | `int(11)` |
| `tbl_equipment_borrowing` | `borrow_id` | `bigint(20) unsigned` |
| `tbl_equipment_borrowing` | `resident_id` | `bigint(20) unsigned` |
| `tbl_equipment_borrowing` | `equipment_id` | `bigint(20) unsigned` |
| `tbl_equipment_borrowing` | `quantity` | `int(11)` |
| `tbl_info_materials` | `files_id` | `bigint(20) unsigned` |
| `tbl_info_materials` | `uploader_id` | `bigint(20) unsigned` |
| `tbl_info_materials` | `file_size` | `int(11)` |
| `tbl_recipients` | `recipient_id` | `bigint(20) unsigned` |
| `tbl_recipients` | `sms_log_id` | `bigint(20) unsigned` |
| `tbl_recipients` | `resident_id` | `bigint(20) unsigned` |
| `tbl_residents` | `resident_id` | `bigint(20) unsigned` |
| `tbl_residents` | `barangay_id` | `bigint(20) unsigned` |
| `tbl_services` | `service_id` | `bigint(20) unsigned` |
| `tbl_service_request` | `request_id` | `bigint(20) unsigned` |
| `tbl_service_request` | `resident_id` | `bigint(20) unsigned` |
| `tbl_service_request` | `service_id` | `bigint(20) unsigned` |
| `tbl_service_request` | `vehicle_id` | `bigint(20) unsigned` |
| `tbl_service_request` | `processed_by` | `bigint(20) unsigned` |
| `tbl_service_translations` | `service_translation_id` | `bigint(20) unsigned` |
| `tbl_service_translations` | `service_id` | `bigint(20) unsigned` |
| `tbl_sms_logs` | `sms_log_id` | `bigint(20) unsigned` |
| `tbl_sms_logs` | `sender_id` | `bigint(20) unsigned` |
| `tbl_sms_logs` | `target_area_id` | `bigint(20) unsigned` |
| `tbl_sms_logs` | `disaster_id` | `bigint(20) unsigned` |
| `tbl_system_logs` | `log_id` | `bigint(20) unsigned` |
| `tbl_system_logs` | `admin_id` | `bigint(20) unsigned` |
| `tbl_system_logs` | `resident_id` | `bigint(20) unsigned` |
| `tbl_system_logs` | `auditable_id` | `bigint(20) unsigned` |
| `tbl_user` | `admin_id` | `bigint(20) unsigned` |
| `tbl_vehicles` | `vehicle_id` | `bigint(20) unsigned` |

### 7.3 Other rendering differences

| Item | MariaDB 10.4.32 (live) | MySQL 8.4 |
| --- | --- | --- |
| `failed_jobs.failed_at` default | `DEFAULT current_timestamp()` | `DEFAULT CURRENT_TIMESTAMP` |
| Server default collation for `utf8mb4` | `utf8mb4_general_ci` | `utf8mb4_0900_ai_ci` |

Every table in this schema names `utf8mb4_unicode_ci` explicitly, so the differing server default does not change the resulting collation. `utf8mb4_unicode_ci` exists on both products.

No other MariaDB-only column type is in use: no `INET6`, no `UUID` type, no `ROW`, no application-time periods, no virtual columns, no `ZEROFILL`.

