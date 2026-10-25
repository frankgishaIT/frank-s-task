-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: risemotive_legacy
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.22.04.4

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `asset_disposals`
--

DROP TABLE IF EXISTS `asset_disposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_disposals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `disposal_type` enum('Sold','Stolen','Damaged','Disposed','Returned to Seller') COLLATE utf8mb4_general_ci NOT NULL,
  `disposal_date` date NOT NULL,
  `book_value` decimal(14,2) NOT NULL,
  `proceeds` decimal(14,2) NOT NULL DEFAULT '0.00',
  `gain_loss` decimal(14,2) NOT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `transaction_id` int DEFAULT NULL,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `refund_status` enum('Not applicable','Received','Receivable') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Not applicable',
  `refund_received_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `asset_disposals_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_valuation_history`
--

DROP TABLE IF EXISTS `asset_valuation_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_valuation_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `valuation_date` date NOT NULL,
  `daily_depreciation` decimal(14,2) NOT NULL DEFAULT '0.00',
  `value` decimal(14,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_asset_day` (`asset_id`,`valuation_date`),
  CONSTRAINT `asset_valuation_history_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26654 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_valuations`
--

DROP TABLE IF EXISTS `asset_valuations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_valuations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `valuation_type` enum('Impairment','Revaluation') NOT NULL,
  `valuation_date` date NOT NULL,
  `value_before` decimal(14,2) NOT NULL,
  `value_after` decimal(14,2) NOT NULL,
  `change_amount` decimal(14,2) NOT NULL,
  `to_reserve` decimal(14,2) NOT NULL DEFAULT '0.00',
  `to_profit_loss` decimal(14,2) NOT NULL DEFAULT '0.00',
  `valuer` varchar(150) DEFAULT NULL,
  `evidence_file` varchar(100) DEFAULT NULL,
  `notes` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_code` varchar(30) NOT NULL,
  `asset_name` varchar(150) NOT NULL,
  `asset_type` varchar(100) NOT NULL,
  `acquisition_date` date NOT NULL,
  `acquisition_value` decimal(14,2) NOT NULL,
  `residual_value` decimal(14,2) NOT NULL DEFAULT '0.00',
  `useful_life_days` int NOT NULL,
  `depreciation_method` enum('Straight-Line') NOT NULL DEFAULT 'Straight-Line',
  `current_value` decimal(14,2) NOT NULL,
  `responsible_department_id` int DEFAULT NULL,
  `status` enum('Active','Under Maintenance','Disposed','Lost') NOT NULL DEFAULT 'Active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `funding_type` enum('Capital Fund purchase','Opening balance') DEFAULT NULL,
  `funding_transaction_id` int DEFAULT NULL,
  `funding_note` varchar(255) DEFAULT NULL,
  `asset_class` enum('Tangible','Purchased software','Internally developed software','Capitalized development cost','Other intangible') NOT NULL DEFAULT 'Tangible',
  `valuation_method` enum('Depreciation','Amortization','No depreciation') NOT NULL DEFAULT 'Depreciation',
  `daily_depreciation_override` decimal(14,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_code` (`asset_code`),
  KEY `responsible_department_id` (`responsible_department_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`responsible_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `assets_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `status` enum('Present','Absent','Late','Leave') COLLATE utf8mb4_general_ci DEFAULT 'Present',
  `workflow_status` enum('Pending Clock-In Approval','Working','Pending Clock-Out Confirmation','Confirmed','Rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Confirmed',
  `manager_id` int DEFAULT NULL,
  `manager_note` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `confirmed_by` int DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `late_minutes` int NOT NULL DEFAULT '0',
  `overtime_minutes` int NOT NULL DEFAULT '0',
  `worked_minutes` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_attendance_manager` (`manager_id`),
  KEY `fk_attendance_confirmed_by` (`confirmed_by`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_attendance_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_attendance_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=402 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `business_parties`
--

DROP TABLE IF EXISTS `business_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_parties` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('Supplier','Payee','Partner') NOT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `business_name` varchar(150) DEFAULT NULL,
  `representative_name` varchar(150) DEFAULT NULL,
  `name` varchar(150) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `payment_account_type` enum('Bank','Phone') DEFAULT NULL,
  `payment_account_details` varchar(150) DEFAULT NULL,
  `province` varchar(100) NOT NULL,
  `district` varchar(100) NOT NULL,
  `sector` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `capital_reconciliations`
--

DROP TABLE IF EXISTS `capital_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `capital_reconciliations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `posted_by` int DEFAULT NULL,
  `posted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` text NOT NULL,
  `summary` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credit_ledger`
--

DROP TABLE IF EXISTS `credit_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credit_ledger` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `entry_type` enum('PRINCIPAL','PAYMENT_PRINCIPAL','CHARGE','PAYMENT_CHARGE','WAIVER') COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `charge_day_no` int DEFAULT NULL,
  `charge_date` date DEFAULT NULL,
  `sale_payment_id` int DEFAULT NULL,
  `principal_balance` decimal(12,2) NOT NULL,
  `charges_balance` decimal(12,2) NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `one_charge_per_day` (`sale_id`,`entry_type`,`charge_day_no`),
  KEY `sale_id` (`sale_id`),
  CONSTRAINT `credit_ledger_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_rewards`
--

DROP TABLE IF EXISTS `customer_rewards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_rewards` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `reward_type_id` int NOT NULL,
  `reward_details` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `reward_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `points_required` int NOT NULL,
  `points_redeemed` int NOT NULL,
  `points_before` int NOT NULL,
  `points_after` int NOT NULL,
  `status` enum('Pending','Fulfilled','Cancelled','Reversed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pending',
  `payment_status` enum('Not applicable','Unpaid','Paid','Refunded') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Not applicable',
  `voucher_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_earned` date NOT NULL,
  `fulfilled_at` datetime DEFAULT NULL,
  `processed_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `cancelled_by` int DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `evidence_file` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `transaction_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `status` (`status`),
  KEY `customer_rewards_type` (`reward_type_id`),
  CONSTRAINT `customer_rewards_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `customer_rewards_type` FOREIGN KEY (`reward_type_id`) REFERENCES `reward_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `loyalty_points` int DEFAULT '0',
  `province` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `district` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sector` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=287 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `status` enum('Active','Inactive') COLLATE utf8mb4_general_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `gender` enum('Male','Female') COLLATE utf8mb4_general_ci NOT NULL,
  `dob` date DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `position` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `employment_type` enum('Permanent','Contract','Intern','Part Time') COLLATE utf8mb4_general_ci DEFAULT 'Permanent',
  `salary` decimal(12,2) DEFAULT '0.00',
  `join_date` date DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_general_ci DEFAULT 'Active',
  `address` text COLLATE utf8mb4_general_ci,
  `photo` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_code` (`employee_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `evidence_file_chunks`
--

DROP TABLE IF EXISTS `evidence_file_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `evidence_file_chunks` (
  `file_id` int NOT NULL,
  `seq` int NOT NULL,
  `data` mediumblob NOT NULL,
  PRIMARY KEY (`file_id`,`seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `evidence_files`
--

DROP TABLE IF EXISTS `evidence_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `evidence_files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mime` varchar(50) NOT NULL,
  `ext` varchar(5) NOT NULL,
  `size_bytes` int NOT NULL,
  `content` longblob NOT NULL,
  `uploaded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fund_movements`
--

DROP TABLE IF EXISTS `fund_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fund_movements` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `fund_id` int NOT NULL,
  `period` char(7) NOT NULL,
  `movement_type` enum('ALLOCATION','CAPITAL_INFLOW','EXPENSE','REVERSAL','ADJUSTMENT','STOCK_OUT','STOCK_IN','ASSET_IN','ASSET_OUT','DEPRECIATION','OPENING_BALANCE') NOT NULL,
  `direction` enum('IN','OUT') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transaction_id` int DEFAULT NULL,
  `source_type` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `allocation_id` int DEFAULT NULL,
  `ref_type` varchar(30) DEFAULT NULL,
  `ref_id` int DEFAULT NULL,
  `reverses_movement_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reverses` (`reverses_movement_id`),
  KEY `idx_fm_fund` (`fund_id`),
  KEY `idx_fm_tx` (`transaction_id`),
  KEY `idx_fm_period` (`period`),
  KEY `idx_fm_alloc` (`allocation_id`),
  KEY `idx_fm_ref` (`ref_type`,`ref_id`),
  CONSTRAINT `fk_fm_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1394 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `funds`
--

DROP TABLE IF EXISTS `funds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `funds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `allocation_percent` decimal(5,2) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_request_comments`
--

DROP TABLE IF EXISTS `leave_request_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_request_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `leave_request_id` int NOT NULL,
  `user_id` int NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `leave_request_id` (`leave_request_id`),
  CONSTRAINT `leave_request_comments_ibfk_1` FOREIGN KEY (`leave_request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_request_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `leave_type` enum('Annual','Sick','Emergency','Unpaid','Other') COLLATE utf8mb4_general_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text COLLATE utf8mb4_general_ci,
  `status` enum('Pending','Manager Approved','Approved','Rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pending',
  `manager_id` int DEFAULT NULL,
  `manager_comment` text COLLATE utf8mb4_general_ci,
  `manager_acted_at` datetime DEFAULT NULL,
  `hr_id` int DEFAULT NULL,
  `hr_comment` text COLLATE utf8mb4_general_ci,
  `hr_acted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_leave_user` (`user_id`),
  KEY `fk_leave_manager` (`manager_id`),
  KEY `fk_leave_hr` (`hr_id`),
  CONSTRAINT `fk_leave_hr` FOREIGN KEY (`hr_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_leave_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_leave_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_payments`
--

DROP TABLE IF EXISTS `loan_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `principal_portion` decimal(14,2) NOT NULL,
  `interest_portion` decimal(14,2) NOT NULL,
  `remaining_balance` decimal(14,2) NOT NULL,
  `notes` text,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `loan_payments_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_payments_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_schedule`
--

DROP TABLE IF EXISTS `loan_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_schedule` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `installment_no` int NOT NULL,
  `due_date` date NOT NULL,
  `principal_due` decimal(14,2) NOT NULL,
  `interest_due` decimal(14,2) NOT NULL,
  `amount_due` decimal(14,2) NOT NULL,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `status` enum('Pending','Partial','Paid') NOT NULL DEFAULT 'Pending',
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  CONSTRAINT `loan_schedule_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=209 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loans`
--

DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lender` varchar(150) NOT NULL,
  `loan_type` varchar(100) NOT NULL,
  `loan_amount` decimal(14,2) NOT NULL,
  `interest_rate` decimal(6,3) NOT NULL,
  `loan_start_date` date NOT NULL,
  `repayment_period` int NOT NULL,
  `repayment_frequency` enum('Weekly','Monthly','Quarterly','Annually') NOT NULL DEFAULT 'Monthly',
  `installment_amount` decimal(14,2) DEFAULT NULL,
  `maturity_date` date NOT NULL,
  `loan_purpose` text,
  `collateral` text,
  `status` enum('Active','Fully Paid','Defaulted','Cancelled') NOT NULL DEFAULT 'Active',
  `total_interest` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_payable` decimal(14,2) NOT NULL DEFAULT '0.00',
  `principal_repaid` decimal(14,2) NOT NULL DEFAULT '0.00',
  `interest_paid` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_paid` decimal(14,2) NOT NULL DEFAULT '0.00',
  `outstanding_balance` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `received_transaction_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `loans_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=664 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll`
--

DROP TABLE IF EXISTS `payroll`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `pay_period` date NOT NULL,
  `basic_salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `present_days` int NOT NULL DEFAULT '0',
  `absent_days` int NOT NULL DEFAULT '0',
  `overtime_minutes` int NOT NULL DEFAULT '0',
  `attendance_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `overtime_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `avg_performance_score` decimal(5,2) DEFAULT NULL,
  `performance_bonus` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sales_commission` decimal(12,2) NOT NULL DEFAULT '0.00',
  `bonus` decimal(12,2) NOT NULL DEFAULT '0.00',
  `deductions` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('Draft','Paid') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Draft',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_user_period` (`user_id`,`pay_period`),
  CONSTRAINT `payroll_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_units`
--

DROP TABLE IF EXISTS `product_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `unit_id` int NOT NULL,
  `conversion_rate` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_unit` (`product_id`,`unit_id`),
  KEY `unit_id` (`unit_id`),
  CONSTRAINT `product_units_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_units_ibfk_2` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int DEFAULT NULL,
  `product_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `product_code` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `item_type` enum('Item','Service') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Item',
  `description` text COLLATE utf8mb4_general_ci,
  `quantity` int DEFAULT '0',
  `reorder_level` int NOT NULL DEFAULT '0',
  `unit` enum('Pieces','Boxes') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pieces',
  `buying_price` decimal(10,2) DEFAULT '0.00',
  `selling_price` decimal(10,2) DEFAULT '0.00',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_code` (`product_code`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=183 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `profit_allocations`
--

DROP TABLE IF EXISTS `profit_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profit_allocations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period` char(7) NOT NULL,
  `product_profit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `service_profit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `expenses_deducted` decimal(15,2) NOT NULL DEFAULT '0.00',
  `net_profit` decimal(15,2) NOT NULL,
  `status` enum('CONFIRMED','REVERSED') NOT NULL DEFAULT 'CONFIRMED',
  `confirmed_by` int DEFAULT NULL,
  `confirmed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `period` (`period`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('Planning','In Progress','Completed','On Hold') COLLATE utf8mb4_general_ci DEFAULT 'Planning',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_order_items`
--

DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL,
  `unit_cost` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `pack_label` varchar(50) NOT NULL DEFAULT 'Piece',
  `pack_size` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  CONSTRAINT `purchase_order_items_ibfk_1` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=317 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier` varchar(150) DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `status` enum('Draft','Ordered','Received','Cancelled') NOT NULL DEFAULT 'Draft',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `created_by` int NOT NULL,
  `ordered_by` int DEFAULT NULL,
  `ordered_at` datetime DEFAULT NULL,
  `received_by` int DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `cancelled_by` int DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `supplier_party_id` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchases`
--

DROP TABLE IF EXISTS `purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL,
  `unit_cost` decimal(12,2) NOT NULL,
  `supplier` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `purchase_order_id` int DEFAULT NULL,
  `supplier_party_id` int DEFAULT NULL,
  `pack_label` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pack_size` int DEFAULT '1',
  `pack_quantity` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=227 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reward_types`
--

DROP TABLE IF EXISTS `reward_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reward_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_general_ci NOT NULL,
  `pays_money` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `department_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_roles_department` (`department_id`),
  CONSTRAINT `fk_roles_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `item_type` enum('Product','Service') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Product',
  `product_id` int DEFAULT NULL,
  `service_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `quantity` int NOT NULL,
  `unit` enum('Pieces','Boxes') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `pack_label` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pack_size` int NOT NULL DEFAULT '1',
  `unit_cost` decimal(15,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sale_items_sale` (`sale_id`),
  KEY `fk_sale_items_product` (`product_id`),
  CONSTRAINT `fk_sale_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1901 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_payments`
--

DROP TABLE IF EXISTS `sale_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by` int NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `sale_payments_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  CONSTRAINT `sale_payments_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=203 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int DEFAULT NULL,
  `sale_date` date NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `charges_accrued` decimal(12,2) NOT NULL DEFAULT '0.00',
  `charges_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `charge_days` int NOT NULL DEFAULT '0',
  `payment_method` enum('Cash','Mobile Money','Bank Transfer','Credit') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Cash',
  `status` enum('Pending Discount Approval','Paid','Partially Paid','Credit','Cancelled') COLLATE utf8mb4_general_ci NOT NULL,
  `discount_requested_by` int DEFAULT NULL,
  `discount_approved_by` int DEFAULT NULL,
  `discount_approved_at` datetime DEFAULT NULL,
  `recorded_by` int DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invoice_issued_at` datetime DEFAULT NULL,
  `payment_deadline` datetime DEFAULT NULL,
  `fully_paid_at` datetime DEFAULT NULL,
  `cancelled_by` int DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cancel_requested_by` int DEFAULT NULL,
  `cancel_requested_at` datetime DEFAULT NULL,
  `cancel_request_reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `loyalty_points_earned` int DEFAULT NULL,
  `points_status` enum('Not applicable','Pending','Awarded','Not awarded') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `points_pending` int NOT NULL DEFAULT '0',
  `points_reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `points_decided_at` datetime DEFAULT NULL,
  `needs_credit_approval` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_sales_customer` (`customer_id`),
  KEY `fk_sales_recorded_by` (`recorded_by`),
  KEY `fk_sales_discount_requested_by` (`discount_requested_by`),
  KEY `fk_sales_discount_approved_by` (`discount_approved_by`),
  KEY `fk_sales_cancelled_by` (`cancelled_by`),
  KEY `fk_sales_cancel_requested_by` (`cancel_requested_by`),
  CONSTRAINT `fk_sales_cancel_requested_by` FOREIGN KEY (`cancel_requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_sales_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_sales_discount_approved_by` FOREIGN KEY (`discount_approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_sales_discount_requested_by` FOREIGN KEY (`discount_requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_sales_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=951 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `migration_name` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration_name` (`migration_name`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sms_log`
--

DROP TABLE IF EXISTS `sms_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `phone` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `event` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `ref_type` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ref_id` int DEFAULT NULL,
  `status` enum('sent','failed','skipped') COLLATE utf8mb4_general_ci NOT NULL,
  `response` text COLLATE utf8mb4_general_ci,
  `error` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_ref` (`event`,`ref_type`,`ref_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=314 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `assigned_to` int DEFAULT NULL,
  `priority` enum('Low','Medium','High') COLLATE utf8mb4_general_ci DEFAULT 'Medium',
  `status` enum('Pending','Accepted','In Progress','Completed','Approved','Rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pending',
  `accepted_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `completion_note` text COLLATE utf8mb4_general_ci,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text COLLATE utf8mb4_general_ci,
  `performance_score` tinyint DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `fk_tasks_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_tasks_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Product',
  `amount` decimal(12,2) NOT NULL,
  `transaction_type` enum('Income','Expense') COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('approved','pending','rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `description` text COLLATE utf8mb4_general_ci,
  `transaction_date` date NOT NULL,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_general_ci,
  `party_id` int DEFAULT NULL,
  `fund_id` int DEFAULT NULL,
  `expense_category` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_automatic` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_by` int DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1131 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `units`
--

DROP TABLE IF EXISTS `units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `names` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('Admin','Manager','Employee') COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `photo` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `monthly_salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `department_id` int DEFAULT NULL,
  `role_id` int DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `department_id` (`department_id`),
  KEY `fk_users_roles` (`role_id`),
  CONSTRAINT `fk_users_roles` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'risemotive_legacy'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-17 11:32:21
