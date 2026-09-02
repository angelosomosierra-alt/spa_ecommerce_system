-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 30, 2026 at 05:43 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `spa_ecommerce_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `actor_name` varchar(150) NOT NULL,
  `actor_role` varchar(50) NOT NULL,
  `action_type` varchar(60) NOT NULL,
  `target_type` varchar(60) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `description` varchar(500) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `actor_id`, `actor_name`, `actor_role`, `action_type`, `target_type`, `target_id`, `description`, `created_at`) VALUES
(1, 2, 'admin Wellness', 'owner', 'therapist_login', 'therapist', 11, 'kent clarence gonzalez clocked in for duty', '2026-07-28 11:06:08'),
(2, 2, 'admin Wellness', 'owner', 'appointment_completed', 'appointment', 1, 'Completed appointment #1 — Basic Pedicure', '2026-07-28 11:07:10'),
(3, 2, 'admin Wellness', 'owner', 'therapist_assigned', 'appointment', 3, 'Assigned therapist(s) to appointment #3 — Ear Candling', '2026-07-28 15:52:46'),
(4, 2, 'admin Wellness', 'owner', 'appointment_approved', 'appointment', 4, 'Approved appointment #4 — Hot Stone (therapist: kent clarence gonzalez)', '2026-07-28 15:54:55'),
(5, 2, 'admin Wellness', 'owner', 'appointment_completed', 'appointment', 5, 'Completed appointment #5 — Basic Manicure', '2026-07-28 16:40:40'),
(6, 2, 'admin Wellness', 'owner', 'service_deleted', 'service', 48, 'Archived service ID 48', '2026-07-28 16:58:35'),
(7, 2, 'admin Wellness', 'owner', 'therapist_login', 'therapist', 8, 'Kenny Ticao clocked in for duty', '2026-07-28 18:00:36'),
(8, 2, 'admin Wellness', 'owner', 'therapist_assigned', 'appointment', 2, 'Assigned therapist(s) to appointment #2 — Ear Candling', '2026-08-10 00:36:02'),
(9, 2, 'admin Wellness', 'owner', 'appointment_declined', 'appointment', 2, 'Declined appointment #2 — Ear Candling', '2026-08-10 00:36:31'),
(10, 2, 'admin Wellness', 'owner', 'therapist_assigned', 'appointment', 6, 'Assigned therapist(s) to appointment #6 — Ear Candling', '2026-08-10 00:51:54'),
(11, 2, 'admin Wellness', 'owner', 'submit_report', 'daily_report', 1, 'Submitted daily report for 2026-08-12', '2026-08-12 13:04:42'),
(12, 2, 'admin Wellness', 'owner', 'submit_report', 'daily_report', 1, 'Submitted daily report for 2026-08-12', '2026-08-12 13:55:42'),
(13, 2, 'admin Wellness', 'owner', 'therapist_login', 'therapist', 11, 'kent clarence gonzalez clocked in for duty', '2026-08-15 08:23:11'),
(14, 2, 'admin Wellness', 'owner', 'therapist_assigned', 'appointment', 9, 'Assigned therapist(s) to appointment #9 — Hot Stone', '2026-08-26 17:04:44'),
(15, 2, 'admin Wellness', 'owner', 'therapist_assigned', 'appointment', 9, 'Assigned therapist(s) to appointment #9 — Hot Stone', '2026-08-26 17:32:00'),
(16, 2, 'admin Wellness', 'owner', 'therapist_assigned', 'appointment', 10, 'Assigned therapist(s) to appointment #10 — Hot Stone', '2026-08-26 17:32:24'),
(17, 2, 'admin Wellness', 'owner', 'therapist_login', 'therapist', 11, 'kent clarence gonzalez clocked in for duty', '2026-08-27 22:32:19'),
(18, 2, 'admin Wellness', 'owner', 'therapist_login', 'therapist', 8, 'Kenny Ticao clocked in for duty', '2026-08-27 22:33:03'),
(19, 2, 'admin Wellness', 'owner', 'therapist_assigned', 'appointment', 11, 'Assigned therapist(s) to appointment #11 — Hot Stone', '2026-08-27 22:40:08'),
(20, 2, 'admin Wellness', 'owner', 'appointment_rescheduled', 'appointment', 11, 'Rescheduled appointment #11 to Aug 28, 2026 5:00 PM', '2026-08-27 22:40:57'),
(21, 2, 'admin Wellness', 'owner', 'appointment_checkedin', 'appointment', 11, 'Checked in customer for appointment #11 — Hot Stone', '2026-08-27 22:41:33'),
(22, 2, 'admin Wellness', 'owner', 'appointment_checkedin', 'appointment', 9, 'Checked in customer for appointment #9 — Hot Stone', '2026-08-27 22:46:55'),
(23, 2, 'admin Wellness', 'owner', 'appointment_completed', 'appointment', 14, 'Completed appointment #14 — Basic Manicure', '2026-08-27 22:52:02'),
(24, 2, 'admin Wellness', 'owner', 'appointment_completed', 'appointment', 11, 'Completed appointment #11 — Hot Stone', '2026-08-27 22:52:39'),
(25, 2, 'admin Wellness', 'owner', 'appointment_completed', 'appointment', 9, 'Completed appointment #9 — Hot Stone', '2026-08-27 23:10:02'),
(26, 2, 'admin Wellness', 'owner', 'appointment_completed', 'appointment', 8, 'Completed appointment #8 — Basic Manicure', '2026-08-27 23:10:14'),
(27, 2, 'admin Wellness', 'owner', 'appointment_completed', 'appointment', 7, 'Completed appointment #7 — Basic Manicure', '2026-08-27 23:10:26'),
(28, 2, 'admin Wellness', 'owner', 'appointment_declined', 'appointment', 13, 'Declined appointment #13 — Basic Manicure', '2026-08-27 23:11:39'),
(29, 2, 'admin Wellness', 'owner', 'appointment_declined', 'appointment', 12, 'Declined appointment #12 — Basic Manicure', '2026-08-27 23:11:44'),
(30, 2, 'admin Wellness', 'owner', 'appointment_checkedin', 'appointment', 10, 'Checked in customer for appointment #10 — Hot Stone', '2026-08-27 23:37:36'),
(31, 2, 'admin Wellness', 'owner', 'therapist_login', 'therapist', 11, 'kent clarence gonzalez clocked in for duty', '2026-08-28 00:01:02'),
(32, 2, 'admin Wellness', 'owner', 'therapist_login', 'therapist', 8, 'Kenny Ticao clocked in for duty', '2026-08-28 00:01:06'),
(33, 2, 'admin Wellness', 'owner', 'therapist_login', 'therapist', 12, 'gab gwapo clocked in for duty', '2026-08-28 00:04:01'),
(34, 2, 'admin Wellness', 'owner', 'appointment_completed', 'appointment', 10, 'Completed appointment #10 — Hot Stone', '2026-08-28 13:40:01');

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `order_item_id` int(11) DEFAULT NULL,
  `appointment_date` datetime NOT NULL,
  `status` enum('pending','approved','assigned','completed','declined','cancelled','refund_requested') NOT NULL DEFAULT 'pending',
  `cancel_reason` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL,
  `people_count` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `service_type` enum('onsite','home') DEFAULT 'onsite',
  `rate_type` enum('regular','home','hotel','influencer') NOT NULL DEFAULT 'regular',
  `partner_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'partners.id — set when rate_type is hotel',
  `charged_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Actual price charged to customer based on rate type',
  `home_address` text DEFAULT NULL,
  `home_notes` text DEFAULT NULL,
  `customer_note` text DEFAULT NULL,
  `therapist_id` int(11) DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `updated_by_name` varchar(120) DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `completed_by_name` varchar(120) DEFAULT NULL,
  `declined_by` int(11) DEFAULT NULL,
  `declined_by_name` varchar(120) DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_by_name` varchar(120) DEFAULT NULL,
  `rescheduled_by` int(11) DEFAULT NULL,
  `rescheduled_by_name` varchar(120) DEFAULT NULL,
  `rescheduled_at` datetime DEFAULT NULL,
  `preferred_therapist_id` int(11) DEFAULT NULL,
  `celebration_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `advance_payment` decimal(10,2) NOT NULL DEFAULT 0.00,
  `advance_payment_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `user_id`, `service_id`, `order_item_id`, `appointment_date`, `status`, `cancel_reason`, `approved_by`, `approved_by_name`, `people_count`, `created_at`, `service_type`, `rate_type`, `partner_id`, `charged_price`, `home_address`, `home_notes`, `customer_note`, `therapist_id`, `duration_minutes`, `updated_by_name`, `completed_by`, `completed_by_name`, `declined_by`, `declined_by_name`, `cancelled_by`, `cancelled_by_name`, `rescheduled_by`, `rescheduled_by_name`, `rescheduled_at`, `preferred_therapist_id`, `celebration_discount`, `advance_payment`, `advance_payment_date`) VALUES
(1, 42, 7, 1, '2026-07-28 11:07:00', 'completed', NULL, NULL, NULL, 1, '2026-07-28 03:06:31', 'onsite', 'regular', NULL, 289.00, NULL, NULL, '', NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(2, 43, 40, 4, '2026-07-28 16:06:00', 'declined', NULL, NULL, NULL, 1, '2026-07-28 06:04:46', 'onsite', 'regular', NULL, 250.00, '', '', 'request hairnet.', NULL, NULL, NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, 11, 0.00, 0.00, NULL),
(3, 43, 40, 5, '2026-07-28 17:50:00', 'assigned', NULL, NULL, NULL, 1, '2026-07-28 07:49:37', 'onsite', 'regular', NULL, 250.00, '', '', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(4, 43, 39, 6, '2026-07-29 10:00:00', 'assigned', NULL, 2, 'admin Wellness', 1, '2026-07-28 07:54:01', 'onsite', 'regular', NULL, 300.00, '', '', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 11, 0.00, 0.00, NULL),
(5, 42, 6, 7, '2026-07-28 16:27:00', 'completed', NULL, NULL, NULL, 1, '2026-07-28 08:25:38', 'onsite', 'regular', NULL, 239.00, NULL, NULL, '', NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(6, 46, 40, 8, '2026-08-13 15:30:00', 'assigned', NULL, NULL, NULL, 1, '2026-08-09 16:49:19', 'onsite', 'regular', NULL, 250.00, '', '', 'Light pressure · Firm pressure', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 11, 0.00, 0.00, NULL),
(7, 42, 6, 9, '2026-08-15 10:00:00', 'completed', NULL, NULL, NULL, 1, '2026-08-15 01:38:59', 'onsite', 'regular', NULL, 239.00, NULL, NULL, '', NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(8, 42, 6, 10, '2026-08-15 10:30:00', 'completed', NULL, NULL, NULL, 1, '2026-08-15 01:47:43', 'onsite', 'regular', NULL, 239.00, NULL, NULL, '', NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(9, 43, 39, 11, '2026-08-27 10:00:00', 'completed', NULL, 2, 'admin Wellness', 1, '2026-08-26 09:04:18', 'onsite', 'regular', NULL, 300.00, '', '', '', NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(10, 43, 39, 12, '2026-08-27 10:00:00', 'completed', NULL, 2, 'admin Wellness', 1, '2026-08-26 09:31:49', 'onsite', 'regular', NULL, 300.00, '', '', '', NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(11, 43, 39, 13, '2026-08-28 17:00:00', 'completed', NULL, 2, 'admin Wellness', 1, '2026-08-27 09:57:43', 'onsite', 'regular', NULL, 300.00, '', '', '', NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, 2, 'admin Wellness', '2026-08-27 22:40:57', NULL, 0.00, 0.00, NULL),
(12, 42, 6, 14, '2026-08-28 16:02:00', 'declined', NULL, NULL, NULL, 1, '2026-08-27 14:35:41', 'onsite', 'regular', NULL, 239.00, NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(13, 42, 6, 15, '2026-08-28 11:00:00', 'declined', NULL, NULL, NULL, 3, '2026-08-27 14:48:27', 'onsite', 'regular', NULL, 717.00, NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(14, 42, 6, 16, '2026-08-30 10:00:00', 'completed', NULL, NULL, NULL, 3, '2026-08-27 14:50:30', 'onsite', 'regular', NULL, 717.00, NULL, NULL, '', NULL, NULL, NULL, 2, 'admin Wellness', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(15, 42, 6, 17, '2026-08-28 10:00:00', 'assigned', NULL, NULL, NULL, 1, '2026-08-27 15:46:53', 'onsite', 'regular', NULL, 239.00, NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(16, 42, 6, 18, '2026-08-28 11:02:00', 'assigned', NULL, NULL, NULL, 1, '2026-08-27 15:47:21', 'onsite', 'regular', NULL, 239.00, NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(17, 42, 6, 19, '2026-08-28 10:30:00', 'assigned', NULL, NULL, NULL, 1, '2026-08-27 16:01:25', 'onsite', 'regular', NULL, 239.00, NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(18, 42, 6, 20, '2026-08-28 12:03:00', 'assigned', NULL, NULL, NULL, 1, '2026-08-27 16:01:43', 'onsite', 'regular', NULL, 239.00, NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(19, 42, 33, 21, '2026-08-28 13:02:00', 'assigned', NULL, NULL, NULL, 3, '2026-08-27 16:07:25', 'onsite', 'regular', NULL, 1197.00, NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(20, 43, 39, 22, '2026-08-28 10:00:00', 'pending', NULL, NULL, NULL, 1, '2026-08-27 16:08:48', 'onsite', 'regular', NULL, 300.00, '', '', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(21, 43, 39, 23, '2026-08-28 10:00:00', 'pending', NULL, NULL, NULL, 3, '2026-08-27 16:09:06', 'onsite', 'regular', NULL, 900.00, '', '', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL),
(22, 43, 39, 24, '2026-08-28 15:00:00', 'pending', NULL, NULL, NULL, 3, '2026-08-27 16:09:47', 'onsite', 'regular', NULL, 900.00, '', '', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 11, 0.00, 0.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `appointment_extra_services`
--

CREATE TABLE `appointment_extra_services` (
  `id` int(10) UNSIGNED NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `therapist_id` int(11) DEFAULT NULL,
  `person_label` varchar(50) NOT NULL DEFAULT 'Person 1' COMMENT 'e.g. Person 1, Person 2',
  `charged_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rate_type` enum('regular','home','hotel','influencer') NOT NULL DEFAULT 'regular',
  `payment_method` enum('cash','card','qrph') NOT NULL DEFAULT 'cash',
  `payment_status` enum('paid','unpaid') NOT NULL DEFAULT 'paid',
  `notes` text DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL COMMENT 'admin user_id who added it',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paymongo_reference` varchar(100) DEFAULT NULL,
  `paymongo_method` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointment_extra_services`
--

INSERT INTO `appointment_extra_services` (`id`, `appointment_id`, `service_id`, `therapist_id`, `person_label`, `charged_price`, `commission`, `rate_type`, `payment_method`, `payment_status`, `notes`, `added_by`, `created_at`, `paymongo_reference`, `paymongo_method`) VALUES
(1, 1, 39, 11, 'Person 1', 300.00, 90.00, 'regular', 'cash', 'paid', '', 2, '2026-07-28 11:07:01', '', '');

-- --------------------------------------------------------

--
-- Table structure for table `appointment_therapists`
--

CREATE TABLE `appointment_therapists` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `assigned_at` datetime DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL,
  `commission` decimal(10,2) NOT NULL DEFAULT 0.00,
  `people_handled` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointment_therapists`
--

INSERT INTO `appointment_therapists` (`id`, `appointment_id`, `therapist_id`, `assigned_at`, `notes`, `commission`, `people_handled`) VALUES
(1, 1, 11, '2026-07-28 11:06:31', '', 86.70, 1),
(3, 3, 11, '2026-07-28 15:52:28', '', 0.00, 1),
(4, 4, 11, '2026-07-28 15:54:01', 'Customer preferred therapist', 0.00, 1),
(5, 5, 11, '2026-07-28 16:25:38', '', 71.70, 1),
(6, 2, 11, '2026-08-10 00:35:55', '', 0.00, 1),
(9, 7, 11, '2026-08-15 09:38:59', '', 71.70, 1),
(10, 8, 11, '2026-08-15 09:47:43', '', 71.70, 1),
(12, 9, 11, '2026-08-26 17:31:56', '', 90.00, 1),
(13, 10, 11, '2026-08-26 17:32:19', '', 90.00, 1),
(14, 12, 8, '2026-08-27 22:35:41', '', 0.00, 1),
(15, 11, 8, '2026-08-27 22:40:01', '', 0.00, 1),
(16, 13, 11, '2026-08-27 22:48:27', '', 215.10, 3),
(17, 14, 11, '2026-08-27 22:50:30', '', 215.10, 3),
(18, 15, 11, '2026-08-27 23:46:53', '', 71.70, 1),
(19, 16, 11, '2026-08-27 23:47:21', '', 71.70, 1),
(20, 17, 11, '2026-08-28 00:01:25', '', 71.70, 1),
(21, 18, 11, '2026-08-28 00:01:43', '', 71.70, 1),
(22, 19, 11, '2026-08-28 00:07:25', '', 359.10, 3),
(23, 22, 11, '2026-08-28 00:09:47', 'Customer preferred therapist', 0.00, 3);

-- --------------------------------------------------------

--
-- Table structure for table `business_expenses`
--

CREATE TABLE `business_expenses` (
  `id` int(11) UNSIGNED NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'misc',
  `label` varchar(150) NOT NULL DEFAULT '',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `verified_by_pin` char(4) DEFAULT NULL COMMENT 'PIN of cashier who entered it',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `business_expenses`
--

INSERT INTO `business_expenses` (`id`, `expense_date`, `category`, `label`, `amount`, `notes`, `added_by`, `verified_by_pin`, `created_at`) VALUES
(1, '2026-07-28', 'water', 'water', 50.00, '', 2, NULL, '2026-07-28 16:18:14'),
(2, '2026-08-12', 'water', 'water jug', 30.00, '', 2, NULL, '2026-08-12 13:56:25'),
(3, '2026-08-28', 'water', 'water', 150.00, '', 2, NULL, '2026-08-28 14:02:33');

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cart`
--

INSERT INTO `cart` (`id`, `user_id`, `product_id`, `quantity`, `created_at`, `updated_at`) VALUES
(39, 43, 9, 1, '2026-08-28 03:48:06', '2026-08-28 03:48:06');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('product','service') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `type`, `created_at`) VALUES
(1, 'Nail Care', 'service', '2026-03-22 09:32:15'),
(2, 'Hair Services', 'service', '2026-03-22 09:32:15'),
(3, 'Brows Services', 'service', '2026-03-22 09:32:15'),
(5, 'Facial', 'service', '2026-03-22 09:32:15'),
(6, 'Body Treatment', 'service', '2026-03-22 09:32:15'),
(7, 'Lotions & Oils', 'product', '2026-03-22 09:32:15'),
(8, 'Skincare', 'product', '2026-03-22 09:32:15'),
(9, 'Bath & Body', 'product', '2026-03-22 09:32:15'),
(13, 'Nail Extension', 'service', '2026-03-24 16:03:39'),
(17, 'Lashes', 'service', '2026-03-24 16:10:58'),
(19, 'Packages', 'service', '2026-03-24 16:26:57'),
(20, 'Other Services', 'service', '2026-03-24 16:42:56'),
(22, 'Japanese Head Spa', 'service', '2026-03-24 16:50:22'),
(24, 'Body Scrub', 'service', '2026-03-24 17:13:16'),
(27, 'Foot Services', 'service', '2026-03-24 17:43:53'),
(28, 'Massage Service', 'service', '2026-03-24 17:44:24'),
(29, 'Waxing Service', 'service', '2026-03-24 17:44:36'),
(30, 'Additional in Massage Service', 'service', '2026-03-24 18:28:36'),
(32, 'oils', 'product', '2026-07-21 11:54:04');

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `subject` varchar(200) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contact_messages`
--

INSERT INTO `contact_messages` (`id`, `name`, `email`, `subject`, `message`, `created_at`) VALUES
(1, 'kent', 'kent@gmail.com', 'wew', 'we', '2026-08-28 05:58:53');

-- --------------------------------------------------------

--
-- Table structure for table `daily_product_sales`
--

CREATE TABLE `daily_product_sales` (
  `id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `particular` varchar(255) NOT NULL COMMENT 'Product name or description',
  `qty` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Unit price',
  `amount` decimal(10,2) GENERATED ALWAYS AS (`qty` * `price`) STORED COMMENT 'Auto-computed: qty × price',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Manual product sales log for walk-in customers not processed through orders';

-- --------------------------------------------------------

--
-- Table structure for table `daily_reports`
--

CREATE TABLE `daily_reports` (
  `id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `opening_cashier` varchar(120) NOT NULL DEFAULT '',
  `closing_cashier` varchar(120) NOT NULL DEFAULT '',
  `cash_on_hand` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Physical cash counted at end of day (COH)',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` int(11) DEFAULT NULL,
  `pos_reading` decimal(10,2) NOT NULL DEFAULT 0.00,
  `maya_dp` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='One row per business day — header for the daily sales report';

--
-- Dumping data for table `daily_reports`
--

INSERT INTO `daily_reports` (`id`, `report_date`, `opening_cashier`, `closing_cashier`, `cash_on_hand`, `notes`, `created_by`, `created_at`, `updated_at`, `is_locked`, `locked_at`, `locked_by`, `pos_reading`, `maya_dp`) VALUES
(1, '2026-08-12', 'kent', 'kent', 1439.00, '', 2, '2026-08-12 12:47:50', '2026-08-12 13:55:46', 0, NULL, NULL, 0.00, 0.00),
(2, '2026-08-28', 'kent', 'kent', 0.00, '', 2, '2026-08-28 12:17:13', '2026-08-28 12:17:21', 0, NULL, NULL, 300.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `daily_report_denominations`
--

CREATE TABLE `daily_report_denominations` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `denomination` decimal(8,2) NOT NULL COMMENT 'e.g. 1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.5, 0.1, 0.05',
  `quantity` int(11) NOT NULL DEFAULT 0,
  `total` decimal(10,2) GENERATED ALWAYS AS (`denomination` * `quantity`) STORED COMMENT 'Auto-computed: denomination × quantity'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Physical cash breakdown per denomination for each daily report';

--
-- Dumping data for table `daily_report_denominations`
--

INSERT INTO `daily_report_denominations` (`id`, `report_id`, `denomination`, `quantity`) VALUES
(1, 1, 1000.00, 1),
(2, 1, 500.00, 0),
(3, 1, 200.00, 2),
(4, 1, 100.00, 0),
(5, 1, 50.00, 0),
(6, 1, 20.00, 1),
(7, 1, 10.00, 1),
(8, 1, 5.00, 1),
(9, 1, 1.00, 4);

-- --------------------------------------------------------

--
-- Table structure for table `daily_report_manual_entries`
--

CREATE TABLE `daily_report_manual_entries` (
  `id` int(10) UNSIGNED NOT NULL,
  `report_date` date NOT NULL,
  `category` enum('service','product','addon','gc_voucher','refund','other') NOT NULL DEFAULT 'other',
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `entry_type` enum('addition','correction') NOT NULL DEFAULT 'addition',
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(150) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_voided` tinyint(1) NOT NULL DEFAULT 0,
  `voided_by_name` varchar(150) DEFAULT NULL,
  `voided_reason` varchar(255) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_report_spreadsheet_rows`
--

CREATE TABLE `daily_report_spreadsheet_rows` (
  `id` int(10) UNSIGNED NOT NULL,
  `source_appointment_id` int(11) DEFAULT NULL,
  `report_date` date NOT NULL,
  `row_order` int(11) NOT NULL DEFAULT 0,
  `time_in` varchar(20) DEFAULT NULL,
  `time_out` varchar(20) DEFAULT NULL,
  `slip_no` varchar(50) DEFAULT NULL,
  `client_name` varchar(150) DEFAULT NULL,
  `service_name` varchar(150) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `stylist` varchar(150) DEFAULT NULL,
  `therapist_id` int(11) DEFAULT NULL,
  `regular_price` decimal(10,2) DEFAULT 0.00,
  `promo_price` decimal(10,2) DEFAULT 0.00,
  `celeb_10` decimal(10,2) DEFAULT 0.00,
  `disc_20_pwd` decimal(10,2) DEFAULT 0.00,
  `comm_30` decimal(10,2) DEFAULT 0.00,
  `comm_20` decimal(10,2) DEFAULT 0.00,
  `comm_15` decimal(10,2) DEFAULT 0.00,
  `disc_50_staff` decimal(10,2) DEFAULT 0.00,
  `net_sales` decimal(10,2) NOT NULL DEFAULT 0.00,
  `mode_of_payment` varchar(50) DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `is_refund` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_name` varchar(150) NOT NULL DEFAULT '',
  `updated_by_name` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `daily_report_spreadsheet_rows`
--

INSERT INTO `daily_report_spreadsheet_rows` (`id`, `source_appointment_id`, `report_date`, `row_order`, `time_in`, `time_out`, `slip_no`, `client_name`, `service_name`, `service_id`, `stylist`, `therapist_id`, `regular_price`, `promo_price`, `celeb_10`, `disc_20_pwd`, `comm_30`, `comm_20`, `comm_15`, `disc_50_staff`, `net_sales`, `mode_of_payment`, `remarks`, `is_refund`, `created_by_name`, `updated_by_name`, `created_at`, `updated_at`) VALUES
(1, NULL, '2026-08-11', 1, '', '', '', 'kent', 'Basic Manicure', 6, 'kent clarence gonzalez', 11, 239.00, 239.00, 0.00, 0.00, 71.70, 0.00, 0.00, 0.00, 167.30, 'Cash', 'walkin', 0, 'admin Wellness', NULL, '2026-08-11 21:21:36', NULL),
(2, NULL, '2026-08-11', 2, '', '', '', 'keanne', 'Basic Pedicure', 7, 'kent clarence gonzalez', 11, 289.00, 231.20, 0.00, 57.80, 69.36, 0.00, 0.00, 0.00, 161.84, 'Cash', 'walk in', 0, 'admin Wellness', NULL, '2026-08-11 21:35:18', NULL),
(3, NULL, '2026-08-11', 3, '', '', '', 'khen', 'Brow Lamination', 21, 'kent clarence gonzalez', 11, 799.00, 399.50, 0.00, 0.00, 119.85, 0.00, 0.00, 399.50, 279.65, 'GCash', 'appointment', 0, 'admin Wellness', NULL, '2026-08-11 21:35:58', NULL),
(4, NULL, '2026-08-12', 1, '', '', '', 'kent', 'Basic Manicure', 6, '', 0, 239.00, 239.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 239.00, 'Cash', 'walkin', 0, 'admin Wellness', NULL, '2026-08-12 12:47:31', NULL),
(5, NULL, '2026-08-12', 2, '', '', '', 'keanne', 'Basic Manicure', 6, 'kent clarence gonzalez', 11, 239.00, 239.00, 0.00, 0.00, 71.70, 0.00, 0.00, 0.00, 167.30, 'Cash', 'walkin', 0, 'admin Wellness', NULL, '2026-08-12 13:55:06', NULL),
(6, NULL, '2026-08-12', 3, '', '', '', 'cla', 'Basic Pedicure', 7, 'kent clarence gonzalez', 11, 289.00, 289.00, 0.00, 0.00, 86.70, 0.00, 0.00, 0.00, 202.30, 'GCash', 'walkin', 0, 'admin Wellness', NULL, '2026-08-12 13:55:29', NULL),
(7, NULL, '2026-08-15', 1, '', '', '', 'Kent', 'Basic Manicure', 6, 'kent clarence gonzalez', 11, 239.00, 191.20, 0.00, 47.80, 57.36, 0.00, 0.00, 0.00, 133.84, 'Cash', 'walkin', 0, 'admin Wellness', NULL, '2026-08-15 09:37:42', NULL),
(8, 11, '2026-08-28', 0, '05:00 PM', '', '', 'Gonzalez', 'Hot Stone', 39, 'Kenny Ticao', 0, 300.00, 300.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 300.00, 'Cash', '', 0, 'import', NULL, '2026-08-27 22:58:00', NULL),
(9, 14, '2026-08-30', 0, '10:00 AM', '', '1007-2026', 'keanne', 'Basic Manicure', 6, 'kent clarence gonzalez', 0, 239.00, 717.00, 0.00, 0.00, 215.10, 0.00, 0.00, 0.00, 501.90, 'Cash', '', 0, 'import', NULL, '2026-08-27 22:58:07', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `rating` tinyint(4) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gift_certificates`
--

CREATE TABLE `gift_certificates` (
  `id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `type` enum('sold','redeemed') NOT NULL COMMENT 'sold = GC issued today; redeemed = GC used today',
  `series` varchar(50) DEFAULT NULL COMMENT 'GC series/batch identifier',
  `client_name` varchar(120) NOT NULL DEFAULT '',
  `voucher_code` varchar(80) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Gift certificates sold or redeemed per day';

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `link`, `is_read`, `created_at`) VALUES
(1, 42, 'appointment', '🎉 Session Completed!', 'Your Basic Pedicure session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-07-28 03:07:10'),
(2, 43, 'order', '🛍️ Order Placed!', 'Your order #2 (₱418.00) has been placed.', 'appointments.php#orders', 0, '2026-07-28 06:02:19'),
(3, NULL, 'order', '🛍️ New Product Order', 'New product order #2 (₱418.00).', 'orders.php', 0, '2026-07-28 06:02:19'),
(4, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #3 is pending approval.', 'appointments.php', 0, '2026-07-28 06:04:46'),
(5, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #3 by a customer.', 'appointments.php', 0, '2026-07-28 06:04:46'),
(6, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #4 is pending approval.', 'appointments.php', 0, '2026-07-28 07:49:37'),
(7, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #4 by a customer.', 'appointments.php', 0, '2026-07-28 07:49:37'),
(8, 43, 'appointment', '💆 Therapist Assigned — Appointment Confirmed!', 'Your Ear Candling appointment on July 28, 2026 5:50 PM has been confirmed.', 'appointments.php', 0, '2026-07-28 07:52:28'),
(9, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #5 is pending approval.', 'appointments.php', 0, '2026-07-28 07:54:01'),
(10, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #5 by a customer.', 'appointments.php', 0, '2026-07-28 07:54:01'),
(11, 43, 'appointment', '✅ Appointment Confirmed!', 'Your Hot Stone appointment has been confirmed! kent clarence gonzalez will be your therapist. Please arrive by July 29, 2026 10:00 AM.', 'appointments.php', 0, '2026-07-28 07:54:50'),
(12, 42, 'appointment', '🎉 Session Completed!', 'Your Basic Manicure session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-07-28 08:40:25'),
(13, 43, 'appointment', '💆 Therapist Assigned — Appointment Confirmed!', 'Your Ear Candling appointment on July 28, 2026 4:06 PM has been confirmed.', 'appointments.php', 0, '2026-08-09 16:35:55'),
(14, 43, 'appointment', '❌ Appointment Declined', 'Your Ear Candling appointment has been declined.', 'appointments.php', 0, '2026-08-09 16:36:31'),
(15, 46, 'appointment', '📅 Appointment Booked!', 'Your booking #7 is pending approval.', 'appointments.php', 0, '2026-08-09 16:49:19'),
(16, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #7 by a customer.', 'appointments.php', 0, '2026-08-09 16:49:19'),
(17, 46, 'appointment', '💆 Therapist Assigned — Appointment Confirmed!', 'Your Ear Candling appointment on August 13, 2026 3:30 PM has been confirmed.', 'appointments.php', 0, '2026-08-09 16:51:48'),
(18, NULL, 'report_submitted', '📋 Daily Report Submitted', 'Daily report for August 12, 2026 submitted by admin Wellness.', 'daily_report.php?date=2026-08-12', 0, '2026-08-12 05:04:36'),
(19, NULL, 'report_submitted', '📋 Daily Report Submitted', 'Daily report for August 12, 2026 submitted by admin Wellness.', 'daily_report.php?date=2026-08-12', 0, '2026-08-12 05:55:37'),
(20, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #10 is pending approval.', 'appointments.php', 0, '2026-08-26 09:04:18'),
(21, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #10 by a customer.', 'appointments.php', 0, '2026-08-26 09:04:18'),
(22, 43, 'appointment', '💆 Therapist Assigned — Appointment Confirmed!', 'Your Hot Stone appointment on August 27, 2026 10:00 AM has been confirmed.', 'appointments.php', 0, '2026-08-26 09:04:38'),
(23, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #11 is pending approval.', 'appointments.php', 0, '2026-08-26 09:31:49'),
(24, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #11 by a customer.', 'appointments.php', 0, '2026-08-26 09:31:49'),
(25, 43, 'appointment', '💆 Therapist Assigned — Appointment Confirmed!', 'Your Hot Stone appointment on August 27, 2026 10:00 AM has been confirmed.', 'appointments.php', 0, '2026-08-26 09:31:56'),
(26, 43, 'appointment', '💆 Therapist Assigned — Appointment Confirmed!', 'Your Hot Stone appointment on August 27, 2026 10:00 AM has been confirmed.', 'appointments.php', 0, '2026-08-26 09:32:19'),
(27, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #12 is pending approval.', 'appointments.php', 0, '2026-08-27 09:57:43'),
(28, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #12 by a customer.', 'appointments.php', 0, '2026-08-27 09:57:43'),
(29, 43, 'appointment', '💆 Therapist Assigned — Appointment Confirmed!', 'Your Hot Stone appointment on August 28, 2026 10:00 AM has been confirmed.', 'appointments.php', 0, '2026-08-27 14:40:01'),
(30, 43, 'appointment', '📅 Appointment Rescheduled', 'Your appointment has been rescheduled to August 28, 2026 05:00 PM.', 'appointments.php', 0, '2026-08-27 14:40:57'),
(31, 43, 'appointment', '✅ You\'ve been checked in!', 'You have been checked in for your Hot Stone appointment. Your session will begin shortly.', 'appointments.php', 0, '2026-08-27 14:41:33'),
(32, 43, 'appointment', '✅ You\'ve been checked in!', 'You have been checked in for your Hot Stone appointment. Your session will begin shortly.', 'appointments.php', 0, '2026-08-27 14:46:55'),
(33, 42, 'appointment', '🎉 Session Completed!', 'Your Basic Manicure session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-08-27 14:51:57'),
(34, 43, 'appointment', '🎉 Session Completed!', 'Your Hot Stone session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-08-27 14:52:34'),
(35, 43, 'appointment', '🎉 Session Completed!', 'Your Hot Stone session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-08-27 15:09:57'),
(36, 42, 'appointment', '🎉 Session Completed!', 'Your Basic Manicure session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-08-27 15:10:08'),
(37, 42, 'appointment', '🎉 Session Completed!', 'Your Basic Manicure session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-08-27 15:10:20'),
(38, 42, 'appointment', '❌ Appointment Declined', 'Your Basic Manicure appointment has been declined.', 'appointments.php', 0, '2026-08-27 15:11:39'),
(39, 42, 'appointment', '❌ Appointment Declined', 'Your Basic Manicure appointment has been declined.', 'appointments.php', 0, '2026-08-27 15:11:44'),
(40, 43, 'appointment', '✅ You\'ve been checked in!', 'You have been checked in for your Hot Stone appointment. Your session will begin shortly.', 'appointments.php', 0, '2026-08-27 15:37:36'),
(41, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #21 is pending approval.', 'appointments.php', 0, '2026-08-27 16:08:48'),
(42, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #21 by a customer.', 'appointments.php', 0, '2026-08-27 16:08:48'),
(43, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #22 is pending approval.', 'appointments.php', 0, '2026-08-27 16:09:06'),
(44, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #22 by a customer.', 'appointments.php', 0, '2026-08-27 16:09:06'),
(45, 43, 'appointment', '📅 Appointment Booked!', 'Your booking #23 is pending approval.', 'appointments.php', 0, '2026-08-27 16:09:47'),
(46, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #23 by a customer.', 'appointments.php', 0, '2026-08-27 16:09:47'),
(47, 43, 'appointment', '🎉 Session Completed!', 'Your Hot Stone session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-08-28 05:40:01');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_name_snapshot` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `booking_date` datetime DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','online','gcash','maya','qrph','bank','card','bpi_debit','bpi_credit','onsite') DEFAULT 'cash',
  `payment_status` enum('pending_payment','unpaid','paid','refunded','partially_refunded') NOT NULL DEFAULT 'unpaid',
  `paymongo_link_id` varchar(100) DEFAULT NULL,
  `paymongo_reference` varchar(100) DEFAULT NULL,
  `paymongo_payment_id` varchar(100) DEFAULT NULL COMMENT 'pay_xxx ID from PayMongo — needed for refunds',
  `approval_status` enum('pending','approved','declined','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `discount_type` varchar(20) NOT NULL DEFAULT 'none' COMMENT 'none | voucher | senior | pwd',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Peso amount of discount given',
  `final_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'total_amount minus discount_amount',
  `slip_number` varchar(20) DEFAULT NULL COMMENT 'Manual service slip number assigned by receptionist e.g. 1108-2026',
  `paymongo_method` varchar(20) DEFAULT NULL COMMENT 'Actual PayMongo source used: gcash, card, paymaya',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_by_name` varchar(120) DEFAULT NULL,
  `completion_discount_type` varchar(20) NOT NULL DEFAULT 'none',
  `completion_discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `completion_voucher_type` varchar(10) DEFAULT NULL,
  `completion_voucher_value` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `customer_name`, `customer_name_snapshot`, `email`, `phone`, `address`, `booking_date`, `total_amount`, `payment_method`, `payment_status`, `paymongo_link_id`, `paymongo_reference`, `paymongo_payment_id`, `approval_status`, `approved_by`, `approved_by_name`, `created_at`, `discount_type`, `discount_amount`, `final_amount`, `slip_number`, `paymongo_method`, `discount_value`, `updated_by_name`, `completion_discount_type`, `completion_discount_amount`, `completion_voucher_type`, `completion_voucher_value`) VALUES
(1, 42, 'kent', NULL, '', '09999999999', '', '2026-07-28 11:07:00', 289.00, 'cash', 'paid', NULL, NULL, NULL, 'completed', NULL, NULL, '2026-07-28 03:06:31', 'none', 0.00, 289.00, '1001-2026', NULL, 0.00, NULL, 'none', 0.00, 'cash', 0.00),
(2, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', NULL, 418.00, 'onsite', 'unpaid', NULL, NULL, NULL, 'pending', NULL, NULL, '2026-07-28 06:02:19', 'none', 0.00, 418.00, NULL, NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(3, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-07-28 16:06:00', 250.00, 'onsite', 'unpaid', NULL, NULL, NULL, 'declined', NULL, NULL, '2026-07-28 06:04:46', 'none', 0.00, 250.00, NULL, NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(4, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-07-28 17:50:00', 250.00, 'onsite', 'unpaid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-07-28 07:49:37', 'senior', 50.00, 200.00, NULL, NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(5, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-07-29 10:00:00', 300.00, 'onsite', 'unpaid', NULL, NULL, NULL, 'approved', 2, 'admin Wellness', '2026-07-28 07:54:01', 'senior', 60.00, 240.00, NULL, NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(6, 42, 'keanne', NULL, '', '09999999999', '', '2026-07-28 16:27:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'completed', NULL, NULL, '2026-07-28 08:25:38', 'none', 0.00, 239.00, '1002-2026', NULL, 0.00, NULL, 'none', 0.00, 'cash', 0.00),
(7, 46, 'Alyanah Dale Estillore', NULL, 'alyanahdaleestillore12@gmail.com', '09999742501', 'simon ledesma', '2026-08-13 15:30:00', 250.00, 'onsite', 'unpaid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-08-09 16:49:19', 'pwd', 50.00, 200.00, NULL, NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(8, 42, 'kent', NULL, '', '09483941263', '', '2026-08-15 10:00:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'completed', NULL, NULL, '2026-08-15 01:38:59', 'voucher', 0.00, 239.00, '1003-2026', NULL, 0.00, NULL, 'none', 0.00, 'cash', 0.00),
(9, 42, 'keanne', NULL, '', '09483941263', '', '2026-08-15 10:30:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'completed', NULL, NULL, '2026-08-15 01:47:43', 'none', 0.00, 239.00, '1004-2026', NULL, 0.00, NULL, 'none', 0.00, 'cash', 0.00),
(10, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-08-27 10:00:00', 300.00, 'cash', 'paid', NULL, '', NULL, 'completed', NULL, NULL, '2026-08-26 09:04:18', 'none', 0.00, 300.00, NULL, '', 0.00, NULL, 'none', 0.00, 'cash', 0.00),
(11, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-08-27 10:00:00', 300.00, 'cash', 'paid', NULL, NULL, NULL, 'completed', NULL, NULL, '2026-08-26 09:31:49', 'none', 0.00, 300.00, NULL, NULL, 0.00, NULL, 'none', 0.00, 'cash', 0.00),
(12, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-08-28 10:00:00', 300.00, 'cash', 'paid', NULL, '', NULL, 'completed', NULL, NULL, '2026-08-27 09:57:43', 'none', 0.00, 300.00, NULL, '', 0.00, NULL, 'none', 0.00, 'cash', 0.00),
(13, 42, 'kent', NULL, '', '09483941263', '', '2026-08-28 16:02:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'declined', NULL, NULL, '2026-08-27 14:35:41', 'none', 0.00, 239.00, '1005-2026', NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(14, 42, 'kent', NULL, '', '09459619213', '', '2026-08-28 11:00:00', 717.00, 'cash', 'paid', NULL, NULL, NULL, 'declined', NULL, NULL, '2026-08-27 14:48:27', 'none', 0.00, 717.00, '1006-2026', NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(15, 42, 'keanne', NULL, '', '09999999999', '', '2026-08-30 10:00:00', 717.00, 'cash', 'paid', NULL, NULL, NULL, 'completed', NULL, NULL, '2026-08-27 14:50:30', 'none', 0.00, 717.00, '1007-2026', NULL, 0.00, NULL, 'none', 0.00, 'cash', 0.00),
(16, 42, 'keanne', NULL, '', '09999999999', '', '2026-08-28 10:00:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-08-27 15:46:53', 'none', 0.00, 239.00, '1008-2026', NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(17, 42, 'keanne', NULL, '', '09459619213', '', '2026-08-28 11:02:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-08-27 15:47:21', 'none', 0.00, 239.00, '1009-2026', NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(18, 42, 'keanne', NULL, '', '09999999999', '', '2026-08-28 10:30:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-08-27 16:01:25', 'none', 0.00, 239.00, '1010-2026', NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(19, 42, 'keanne', NULL, '', '09999999999', '', '2026-08-28 12:03:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-08-27 16:01:43', 'none', 0.00, 239.00, '1011-2026', NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(20, 42, 'keanne', NULL, '', '09999999999', '', '2026-08-28 13:02:00', 1197.00, 'cash', 'paid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-08-27 16:07:25', 'none', 0.00, 1197.00, '1012-2026', NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(21, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-08-28 10:00:00', 300.00, 'onsite', 'unpaid', NULL, NULL, NULL, 'pending', NULL, NULL, '2026-08-27 16:08:48', 'none', 0.00, 300.00, NULL, NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(22, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-08-28 10:00:00', 900.00, 'onsite', 'unpaid', NULL, NULL, NULL, 'pending', NULL, NULL, '2026-08-27 16:09:06', 'none', 0.00, 900.00, NULL, NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00),
(23, 43, 'Gonzalez', NULL, 'Keer.gonzalez.ui@phinmaed.com', '09483941263', 'Quezon Arevalo', '2026-08-28 15:00:00', 900.00, 'onsite', 'unpaid', NULL, NULL, NULL, 'pending', NULL, NULL, '2026-08-27 16:09:47', 'none', 0.00, 900.00, NULL, NULL, 0.00, NULL, 'none', 0.00, NULL, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `home_service_fee` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `service_id`, `quantity`, `price`, `subtotal`, `home_service_fee`) VALUES
(1, 1, NULL, 7, 1, 289.00, 289.00, 0.00),
(2, 2, 8, NULL, 1, 259.00, 259.00, 0.00),
(3, 2, 7, NULL, 1, 159.00, 159.00, 0.00),
(4, 3, NULL, 40, 1, 250.00, 250.00, 0.00),
(5, 4, NULL, 40, 1, 250.00, 250.00, 0.00),
(6, 5, NULL, 39, 1, 300.00, 300.00, 0.00),
(7, 6, NULL, 6, 1, 239.00, 239.00, 0.00),
(8, 7, NULL, 40, 1, 250.00, 250.00, 0.00),
(9, 8, NULL, 6, 1, 239.00, 239.00, 0.00),
(10, 9, NULL, 6, 1, 239.00, 239.00, 0.00),
(11, 10, NULL, 39, 1, 300.00, 300.00, 0.00),
(12, 11, NULL, 39, 1, 300.00, 300.00, 0.00),
(13, 12, NULL, 39, 1, 300.00, 300.00, 0.00),
(14, 13, NULL, 6, 1, 239.00, 239.00, 0.00),
(15, 14, NULL, 6, 3, 239.00, 717.00, 0.00),
(16, 15, NULL, 6, 3, 239.00, 717.00, 0.00),
(17, 16, NULL, 6, 1, 239.00, 239.00, 0.00),
(18, 17, NULL, 6, 1, 239.00, 239.00, 0.00),
(19, 18, NULL, 6, 1, 239.00, 239.00, 0.00),
(20, 19, NULL, 6, 1, 239.00, 239.00, 0.00),
(21, 20, NULL, 33, 3, 399.00, 1197.00, 0.00),
(22, 21, NULL, 39, 1, 300.00, 300.00, 0.00),
(23, 22, NULL, 39, 3, 300.00, 900.00, 0.00),
(24, 23, NULL, 39, 3, 300.00, 900.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `partners`
--

CREATE TABLE `partners` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('hotel','corporate','other') NOT NULL DEFAULT 'hotel',
  `contact` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `partners`
--

INSERT INTO `partners` (`id`, `name`, `type`, `contact`, `notes`, `status`, `created_at`) VALUES
(1, 'Hotel Partner', 'hotel', NULL, NULL, 'active', '2026-05-15 16:54:27'),
(3, 'massage', 'hotel', '09171234567', '', 'active', '2026-08-10 01:03:19');

-- --------------------------------------------------------

--
-- Table structure for table `partner_rates`
--

CREATE TABLE `partner_rates` (
  `id` int(10) UNSIGNED NOT NULL,
  `partner_id` int(10) UNSIGNED NOT NULL,
  `service_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `image` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `category_id`, `image`, `description`, `price`, `stock`, `created_at`, `deleted_at`) VALUES
(5, 'Organic Shampoo - 100ML', 9, 'product_1774724380_Shampoo.png', 'The Ultimate Hair Detox.\r\nInfused with 100% natural coconut milk, this duo restores silky softness and radiant shine to tired strands. Organic, nutrient-rich, and designed to bring your hair back to life\r\n\r\n100ML', 149.00, 20, '2026-03-28 18:32:48', NULL),
(6, 'Organinc Shampoo - 200 ML', 9, 'product_1774724368_Shampoo.png', 'The Ultimate Hair Detox.\r\nInfused with 100% natural coconut milk, this duo restores silky softness and radiant shine to tired strands. Organic, nutrient-rich, and designed to bring your hair back to life.\r\n\r\n200ML', 249.00, 20, '2026-03-28 18:40:31', NULL),
(7, 'Conditioner - 100 ML', 9, 'product_1774724522_Conditioner.png', 'The Ultimate Hair Detox.\r\nInfused with 100% natural coconut milk, this duo restores silky softness and radiant shine to tired strands. Organic, nutrient-rich, and designed to bring your hair back to life.', 159.00, 13, '2026-03-28 19:02:02', NULL),
(8, 'Conditioner - 200ML', 9, 'product_1774724567_Conditioner.png', 'The Ultimate Hair Detox.\r\nInfused with 100% natural coconut milk, this duo restores silky softness and radiant shine to tired strands. Organic, nutrient-rich, and designed to bring your hair back to life.\r\n200ML', 259.00, 3, '2026-03-28 19:02:47', NULL),
(9, 'Hand Sanitizer', 9, 'product_1774724844_Hand-Sanitizer.jpg', 'Experience premium hygiene with our SCENTSMITH Hand Sanitizer – Bergamot Patchouli (200ml). Crafted with a refreshing blend of citrusy bergamot and earthy patchouli, this sanitizer not only eliminates germs effectively but also leaves a long-lasting, sophisticated scent on your hands.', 250.00, 20, '2026-03-28 19:07:24', NULL),
(10, 'The Pamper Set (Holiday Gift Bag) The Gift of Self-Care.', 9, 'product_1774724911_657444589_1554107539023078_2006236898953284170_n.jpg', 'The Pamper Set (Holiday Gift Bag)\r\nThe Gift of Self-Care.\r\nEverything you need for a spa day at home. This 5-piece set includes a massage oil, body scrub, face towel, shampoo, and conditioner—all wrapped up and ready to give.', 499.00, 20, '2026-03-28 19:08:31', NULL),
(11, 'Soothing Massage Oils', 7, 'product_1774725098_656854241_2424585127993047_6672032975636080307_n.jpg', 'Your Pocket-Sized Escape. Designed for deep relaxation and skin hydration in every 100ml bottle.', 119.00, 20, '2026-03-28 19:11:38', NULL),
(12, 'Milk &amp; Honey Body Scrub', 8, 'product_1774725266_ChatGPT Image Mar 29, 2026, 03_13_15 AM.png', 'Glow with Every Scrub. This revitalizing body scrub is formulated to gently exfoliate, detoxify, and nourish your skin. Infused with the energizing aroma of coffee and the soothing blend of honey and milk, it helps remove dead skin cells while leaving your skin soft, smooth, and naturally radiant.', 175.00, 200, '2026-03-28 19:14:26', NULL),
(13, 'chicken oil', 32, 'product_1784635329_655504764_964705562647548_8315551904422869646_n.jpg', 'chicken oil', 499.50, 12, '2026-07-21 12:02:09', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `receptionist_pins`
--

CREATE TABLE `receptionist_pins` (
  `id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `pin` char(4) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `receptionist_pins`
--

INSERT INTO `receptionist_pins` (`id`, `full_name`, `phone`, `pin`, `created_at`) VALUES
(5, 'maria', '09459619213', '1111', '2026-07-22 15:37:26');

-- --------------------------------------------------------

--
-- Table structure for table `refund_requests`
--

CREATE TABLE `refund_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','refunded','manually_refunded','rejected') NOT NULL DEFAULT 'pending',
  `paymongo_payment_id` varchar(100) DEFAULT NULL COMMENT 'pay_xxx ID needed for PayMongo refund',
  `paymongo_refund_id` varchar(100) DEFAULT NULL COMMENT 'ref_xxx returned by PayMongo',
  `refund_notes` text DEFAULT NULL COMMENT 'Admin notes on refund',
  `processed_by` int(11) DEFAULT NULL COMMENT 'admin user_id who processed it',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `image` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `session_time` int(11) NOT NULL COMMENT 'Session time in minutes',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_home_service` tinyint(1) DEFAULT 0,
  `home_service_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deleted_at` datetime DEFAULT NULL,
  `at_cost` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `name`, `category_id`, `image`, `description`, `price`, `session_time`, `created_at`, `is_home_service`, `home_service_fee`, `deleted_at`, `at_cost`) VALUES
(5, 'Cleaning', 1, 'service_1774366363_Nail Care - Cleaning.jpg', 'Treat your hands and feet to a refreshing Nail Care – Cleaning service. We carefully cleanse, trim, and shape your nails while gently caring for your cuticles, leaving them feeling light, clean, and rejuvenated. A perfect way to relax while keeping your nails naturally beautiful.', 209.00, 30, '2026-03-24 15:32:43', 0, 0.00, NULL, 0.00),
(6, 'Basic Manicure', 1, 'service_1774366433_Nail Care - Basic Manicure.jpg', 'Enjoy a refreshing Basic Manicure that combines nail care with relaxation. Your nails will be carefully shaped and cleaned, cuticles gently treated, followed by a soothing hand massage and a beautiful polish of your choice.', 239.00, 30, '2026-03-24 15:33:53', 0, 0.00, NULL, NULL),
(7, 'Basic Pedicure', 1, 'service_1774366470_Nail Care - Basic Pedicure.jpg', 'Give your feet the care they deserve with our Basic Pedicure. Enjoy thorough nail cleaning, shaping, and cuticle treatment, followed by a soothing foot massage and a polish application that leaves your feet feeling fresh, soft, and revitalized.', 289.00, 30, '2026-03-24 15:34:30', 0, 0.00, NULL, NULL),
(8, 'Gel Manicure', 1, 'service_1774366506_Nail Care - Gel Manicure.jpg', 'Treat yourself to a Gel Manicure that combines nail care and beauty. Your nails will be shaped and cleaned, cuticles gently treated, and finished with a beautiful gel polish that stays shiny and chip-resistant for weeks.', 399.00, 30, '2026-03-24 15:35:06', 0, 0.00, NULL, NULL),
(9, 'Gel Pedicure', 1, 'service_1774366560_Nail Care - Gel Pedicure.jpg', 'Pamper your feet with our Gel Pedicure. Enjoy thorough nail cleaning, shaping, and cuticle care, followed by a soothing foot massage and a beautiful gel polish that stays vibrant, smooth, and shiny for weeks.', 499.00, 30, '2026-03-24 15:36:00', 0, 0.00, NULL, NULL),
(10, 'Gel Removal', 1, 'service_1774367661_Nail Care - Gel removal.jpg', 'Remove old gel polish with care through our Gel Removal service. Designed to keep your natural nails strong and healthy while effectively taking off gel products.', 499.00, 30, '2026-03-24 15:54:21', 0, 0.00, NULL, NULL),
(11, 'Simple', 13, 'service_1774368320_Nail Extension - Simple.jpg', 'Enhance your nails with our Simple Nail Extension service. Designed to give added length and shape while keeping a soft, natural appearance that’s perfect for everyday wear.', 999.00, 30, '2026-03-24 16:05:20', 0, 0.00, NULL, NULL),
(12, 'Personal Design', 13, 'service_1774368557_Nail Extension - Personal Extension.jpg', 'Create nails that match your personality with our Personal Design service. Choose your own colors, patterns, and styles for a one-of-a-kind look made just for you.', 1500.00, 30, '2026-03-24 16:09:17', 0, 0.00, NULL, NULL),
(13, 'Classic', 17, 'service_1774368742_Lash Services - Classic.jpg', 'Achieve effortlessly beautiful eyes with our Classic Lash Service. This technique provides a light, natural enhancement by adding length and subtle volume, giving you a fresh and elegant appearance.', 699.00, 30, '2026-03-24 16:12:22', 0, 0.00, NULL, NULL),
(14, 'Cat Eye / Wispy', 17, 'service_1774368971_Lash Services - Cat Eye or Wispy.jpg', 'Achieve a flirty and textured look with our Cat Eye / Wispy Lashes. Designed with varying lash lengths, this style adds dimension and a natural wispy effect while creating a subtle winged shape for a more defined, glamorous appearance.', 799.00, 30, '2026-03-24 16:16:11', 0, 0.00, NULL, NULL),
(15, 'Semi  Glam', 17, 'service_1774369063_Lash Services - Semi Glam.jpg', 'Enhance your beauty with our Semi Glam Lashes. This set provides a fuller, fluffier appearance than classic lashes, giving your eyes a soft glam effect that’s perfect for both everyday wear and special occasions.', 899.00, 30, '2026-03-24 16:17:43', 0, 0.00, NULL, NULL),
(16, 'Full Glam', 17, 'service_1774369141_Lash Services - Full Glam.jpg', 'Turn heads with our Full Glam Lashes. Designed for ultimate fullness, this set adds intense volume and definition, giving your eyes a rich, dramatic effect that enhances any makeup look.', 999.00, 30, '2026-03-24 16:19:01', 0, 0.00, NULL, NULL),
(17, 'Lash lift', 17, 'service_1774369197_Lash Services - Lash Lift.jpg', 'A treatment that lifts and curls your natural lashes, giving them a longer and more defined appearance without the use of extensions.', 490.00, 30, '2026-03-24 16:19:57', 0, 0.00, NULL, NULL),
(18, 'Lash Removal', 17, 'service_1774369343_Lash Services - Lash Removal.jpg', 'Safely remove old or unwanted lash extensions with our Lash Removal treatment. We use gentle techniques to ensure your natural lashes stay healthy, strong, and ready for your next lash appointment.', 300.00, 30, '2026-03-24 16:22:23', 0, 0.00, NULL, NULL),
(19, 'Brow Shaping (Wax or Thread)', 3, 'service_1774369414_Brows Service - Brow Shaping.jpg', 'Perfectly shaped brows are just a treatment away. We use gentle and precise waxing or threading techniques to remove unwanted hair and create a polished, natural look that frames your eyes beautifully.', 269.00, 30, '2026-03-24 16:23:34', 0, 0.00, NULL, NULL),
(20, 'Brow Tinting', 3, 'service_1774369501_Brows Services - Brow Tinting.jpg', 'Achieve effortlessly beautiful brows with our tinting treatment. We carefully apply a semi-permanent tint to give your eyebrows a consistent, natural-looking shade that complements your features', 349.00, 30, '2026-03-24 16:25:01', 0, 0.00, NULL, NULL),
(21, 'Brow Lamination', 3, 'service_1774369546_Brows Services - Brow Lamination.jpg', 'Enhance your natural brows with our lamination treatment. This service restructures and sets your brow hairs in place, creating a soft, full, and perfectly styled look that lasts for weeks.', 799.00, 30, '2026-03-24 16:25:46', 0, 0.00, NULL, NULL),
(22, 'Brow Shaping + Brow Tinting', 19, 'service_1774370428_Packages - Brow Shaping + Brow Tinting.jpg', 'Enhance your natural brows with this all-in-one package. Includes expert shaping (wax or thread) and brow tinting to add depth, definition, and a more balanced appearance.', 599.00, 30, '2026-03-24 16:40:28', 0, 0.00, NULL, NULL),
(23, 'Brow Lamination + Tint', 19, 'service_1774370542_Packages - Brow Lamination + Tint.jpg', 'Enhance your brows with this all-in-one package. Brow lamination smooths and sets the hairs in place, while tinting adds richness and definition for a naturally fuller appearance.', 999.00, 30, '2026-03-24 16:42:22', 0, 0.00, NULL, NULL),
(24, 'Face Lifting Massage', 20, 'service_1774370849_Other Service - Face Lifting.jpg', 'Our Face Lifting service helps tighten and firm your facial muscles, promoting a lifted, youthful appearance. Ideal for enhancing skin elasticity and restoring a refreshed look.', 479.00, 30, '2026-03-24 16:47:29', 0, 0.00, NULL, NULL),
(25, 'Scalp Scrub', 20, 'service_1774370979_Other Service - Scalp Services.jpg', 'Our Scalp Scrub gently exfoliates and removes buildup, promoting a clean, healthy scalp. Ideal for revitalizing hair roots and improving scalp circulation.', 479.00, 30, '2026-03-24 16:49:39', 0, 0.00, NULL, NULL),
(26, 'Express Head Spa', 22, '0', 'Indulge in our Express Japanese Head Spa. This treatment detoxifies and revitalizes your scalp while providing a soothing massage that relaxes both the mind and body, leaving you refreshed in just a short session.', 1399.00, 45, '2026-03-24 17:05:48', 0, 0.00, NULL, NULL),
(27, 'Luxury Scalp and Basic Facial', 22, 'service_1774372280_Japanese Head Spa - Luxury Scalp and Basic Facial.jpg', 'Indulge in our Express Japanese Head Spa. This treatment detoxifies and revitalizes your scalp while providing a soothing massage that relaxes both the mind and body, leaving you refreshed in just a short session.', 2199.00, 30, '2026-03-24 17:11:20', 0, 0.00, NULL, NULL),
(28, 'Milk + Honey Scrub', 24, 'service_1774373090_Body Scrub - Milk + Honey Scrub.jpg', 'Indulge in a soothing Milk + Honey scrub that cleanses, hydrates, and revitalizes your skin, leaving it radiant and silky-soft.', 649.00, 30, '2026-03-24 17:24:50', 0, 0.00, NULL, NULL),
(29, 'Coffee Detox Scrub', 24, 'service_1774373785_Body Scrub - Coffee Detox Scrub.jpg', 'Our Coffee Detox Scrub exfoliates and detoxifies your skin while stimulating circulation, leaving your body feeling refreshed, smooth, and revitalized.', 649.00, 30, '2026-03-24 17:36:25', 0, 0.00, NULL, NULL),
(30, 'Regular Foot Spa', 27, 'service_1774374722_Foot Service - Regular Foot Spa.jpg', 'Our Regular Foot Spa soothes tired feet, cleanses, and softens the skin. Enjoy a calming soak and gentle massage that refreshes and revitalizes your feet.', 599.00, 30, '2026-03-24 17:52:02', 0, 0.00, NULL, NULL),
(31, 'Foot Spa + Foot Scrub', 27, 'service_1774375352_Foor Service - Foot spa + Foor scrub.jpg', 'Indulge in our Foot Spa + Foot Scrub. This luxurious treatment soothes, exfoliates, and nourishes your feet, leaving them soft, smooth, and completely revitalized.', 649.00, 30, '2026-03-24 18:02:32', 0, 0.00, NULL, NULL),
(32, 'Foot Spa + Foot Scrub + Foot Massage', 19, 'service_1774375721_Foor Servie - Foot Spa + Foot Scrub + Foot Massage.jpg', 'Indulge in our luxurious Foot Spa + Foot Scrub + Foot Massage. This all-in-one treatment softens, exfoliates, and massages your feet for ultimate comfort, relaxation, and rejuvenation.', 849.00, 30, '2026-03-24 18:08:41', 0, 0.00, NULL, NULL),
(33, 'Foot Reflex', 27, 'service_1774376036_Foot Service - Foot reflex.jpg', 'Indulge in our Foot Reflex service, a rejuvenating treatment that uses expert techniques to stimulate reflex points, promoting relaxation, better circulation, and overall well-being.', 399.00, 30, '2026-03-24 18:13:56', 0, 0.00, NULL, NULL),
(34, 'Swedish', 28, 'service_1774376289_Massage Service - Swedish.jpg', 'Indulge in our Swedish Massage, a soothing treatment that eases tension, improves circulation, and restores balance to both body and mind for a deeply relaxing experience.', 849.00, 30, '2026-03-24 18:18:09', 0, 0.00, NULL, NULL),
(35, 'Thai', 28, 'service_1774376465_Massage Service - Thai.jpg', 'Experience a therapeutic Thai Massage that stretches and tones muscles, relieves stress, and restores balance to your body and mind.', 949.00, 30, '2026-03-24 18:21:05', 1, 0.00, NULL, NULL),
(36, 'Combination', 28, 'service_1774376615_Massage Service - Combination.jpg', 'Our Combination Massage blends different massage techniques tailored to your needs, offering muscle relaxation, tension relief, and overall rejuvenation in one session.', 849.00, 30, '2026-03-24 18:23:35', 0, 0.00, NULL, NULL),
(37, 'Shiatsu', 28, 'service_1774376822_Massage Service - Shiatsu.jpg', 'Experience a Japanese-inspired Shiatsu Massage that stimulates pressure points, relieves muscle stiffness, and promotes overall wellness and relaxation.', 949.00, 30, '2026-03-24 18:27:02', 0, 0.00, NULL, NULL),
(38, 'Ventosa', 28, 'service_1774377305_Massage Service - Ventosa.jpg', 'Indulge in our Ventosa treatment, using therapeutic suction to release tight muscles, restore energy flow, and promote deep relaxation and wellness.', 300.00, 30, '2026-03-24 18:35:05', 0, 0.00, NULL, NULL),
(39, 'Hot Stone', 30, 'service_1774377382_Massage Service - Hot Stone.jpg', 'Experience a soothing massage with heated stones that melt away stress, ease muscle stiffness, and promote overall relaxation and wellness.', 300.00, 30, '2026-03-24 18:36:22', 1, 100.00, NULL, 0.00),
(40, 'Ear Candling', 30, 'service_1774377488_Massage Service - Ear candling.jpg', 'Experience a safe and natural ear cleansing method that helps clear impurities, improves ear health, and provides a sense of calm and relief.', 250.00, 10, '2026-03-24 18:38:08', 0, 0.00, NULL, NULL),
(41, 'Hotel / Home Service', 30, 'service_1774377890_Massage Service - Hotel Home Service.jpg', 'Experience the ultimate convenience with our Hotel/Home Service Massage. We provide tailored massage treatments at your location for complete relaxation and comfort.', 300.00, 30, '2026-03-24 18:44:50', 0, 0.00, NULL, NULL),
(42, 'Under Arm', 29, 'service_1774378459_Waxing Service - Under arm.jpg', 'Experience a precise and gentle underarm waxing treatment that leaves your skin soft, smooth, and hair-free.', 359.00, 10, '2026-03-24 18:54:19', 0, 0.00, NULL, NULL),
(43, 'Chest', 29, 'service_1774378511_Waxing Service - Chest Wax.jpg', 'Experience a precise and gentle chest waxing treatment that leaves your skin smooth, hair-free, and refreshed.', 359.00, 10, '2026-03-24 18:55:11', 0, 0.00, NULL, NULL),
(44, 'Full Arm', 29, 'service_1774378711_Waxing Service - Full arm.jpg', 'Experience precise and gentle full arm waxing for clean, hair-free skin that feels refreshed and silky.', 799.00, 10, '2026-03-24 18:58:31', 0, 0.00, NULL, NULL),
(45, 'Half Arm', 29, 'service_1774378830_Waxing Service - half arm.jpg', 'Our Half Arm Waxing service removes unwanted hair from the elbow to the wrist (or shoulder to elbow), leaving your arms soft, smooth, and well-groomed.', 399.00, 10, '2026-03-24 19:00:30', 0, 0.00, NULL, NULL),
(46, 'Full Leg', 29, 'service_1774378889_Waxing Service - Full leg.jpg', 'Our Full Leg Waxing service removes unwanted hair from thigh to ankle, leaving your legs soft, smooth, and beautifully groomed.', 1079.00, 10, '2026-03-24 19:01:29', 0, 0.00, NULL, NULL),
(47, 'Bikini', 29, 'service_1774378935_Waxing Service - Bikini.jpg', 'Our Bikini Waxing service removes unwanted hair for a neat, clean, and well-groomed bikini area.', 839.00, 10, '2026-03-24 19:02:15', 0, 0.00, NULL, NULL),
(48, 'Chin', 29, 'service_1774378986_Waxing Service - Chin.jpg', 'Our Chin Waxing service gently removes unwanted hair for a smooth, clean, and well-groomed look.', 359.00, 10, '2026-03-24 19:03:06', 0, 0.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES
(1, 'receptionist_login_start', '00:00', 2, '2026-05-23 20:34:12'),
(2, 'receptionist_login_end', '23:59', 2, '2026-05-23 20:34:12'),
(3, 'receptionist_timezone', 'Asia/Manila', 2, '2026-05-23 20:34:12');

-- --------------------------------------------------------

--
-- Table structure for table `therapists`
--

CREATE TABLE `therapists` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `specialties` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_generalist` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `therapists`
--

INSERT INTO `therapists` (`id`, `user_id`, `full_name`, `phone`, `specialties`, `created_at`, `is_generalist`) VALUES
(8, NULL, 'Kenny Ticao', '09631382105', '3', '2026-06-29 06:49:26', 0),
(11, NULL, 'kent clarence gonzalez', '09483941263', '', '2026-07-15 14:48:22', 0),
(12, NULL, 'gab gwapo', '0967676767', '45', '2026-07-21 12:06:50', 0),
(13, NULL, 'gab dos', '0996767676', '60', '2026-07-21 12:09:11', 0);

-- --------------------------------------------------------

--
-- Table structure for table `therapist_attendance`
--

CREATE TABLE `therapist_attendance` (
  `id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `duty_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `commission` decimal(10,2) DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `rotation_order` int(11) DEFAULT NULL,
  `is_on_break` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `therapist_attendance`
--

INSERT INTO `therapist_attendance` (`id`, `therapist_id`, `duty_date`, `time_in`, `time_out`, `commission`, `notes`, `created_at`, `rotation_order`, `is_on_break`) VALUES
(1, 11, '2026-07-28', '11:06:00', NULL, 0.00, NULL, '2026-07-28 03:06:08', 5, 0),
(2, 8, '2026-07-28', '18:00:00', NULL, 0.00, NULL, '2026-07-28 10:00:36', 6, 0),
(3, 11, '2026-08-15', '08:23:00', NULL, 0.00, NULL, '2026-08-15 00:23:11', 2, 0),
(4, 11, '2026-08-27', '22:32:00', NULL, 0.00, NULL, '2026-08-27 14:32:19', 7, 0),
(5, 8, '2026-08-27', '22:32:00', NULL, 0.00, NULL, '2026-08-27 14:33:03', 4, 0),
(6, 11, '2026-08-28', '00:00:00', NULL, 0.00, NULL, '2026-08-27 16:01:02', 4, 0),
(7, 8, '2026-08-28', '00:01:00', NULL, 0.00, NULL, '2026-08-27 16:01:06', 2, 0),
(8, 12, '2026-08-28', '00:03:00', NULL, 0.00, NULL, '2026-08-27 16:04:01', 3, 0);

-- --------------------------------------------------------

--
-- Table structure for table `therapist_commission`
--

CREATE TABLE `therapist_commission` (
  `id` int(10) UNSIGNED NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `commission_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `influencer_flat_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `therapist_commission`
--

INSERT INTO `therapist_commission` (`id`, `therapist_id`, `service_id`, `commission_percent`, `influencer_flat_rate`, `created_at`, `updated_at`) VALUES
(303, 8, 40, 10.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(304, 8, 39, 0.00, 0.00, '2026-06-29 14:49:51', '2026-07-06 20:53:37'),
(306, 8, 29, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(307, 8, 28, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(308, 8, 21, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(309, 8, 19, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(310, 8, 20, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(311, 8, 33, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(312, 8, 31, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(313, 8, 30, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(314, 8, 26, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(315, 8, 27, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(316, 8, 14, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(317, 8, 13, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(318, 8, 16, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(319, 8, 17, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(320, 8, 18, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(321, 8, 15, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(322, 8, 36, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(323, 8, 37, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(324, 8, 34, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(325, 8, 35, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(326, 8, 38, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(327, 8, 6, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(328, 8, 7, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(329, 8, 5, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(330, 8, 8, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(331, 8, 9, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(332, 8, 10, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(333, 8, 12, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(334, 8, 11, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(335, 8, 24, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(336, 8, 25, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(337, 8, 23, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(338, 8, 22, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(339, 8, 32, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(340, 8, 47, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(341, 8, 43, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(343, 8, 44, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(344, 8, 46, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(345, 8, 45, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(346, 8, 42, 0.00, 0.00, '2026-06-29 14:49:51', '2026-06-29 14:49:51'),
(394, 11, 40, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(395, 11, 39, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(397, 11, 29, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(398, 11, 28, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(399, 11, 21, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(400, 11, 19, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(401, 11, 20, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(402, 11, 33, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(403, 11, 31, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(404, 11, 30, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(405, 11, 26, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(406, 11, 6, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(407, 11, 7, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(408, 11, 5, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(409, 11, 8, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(410, 11, 9, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(411, 11, 10, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(412, 11, 11, 30.00, 0.00, '2026-07-15 22:48:55', '2026-07-15 22:48:55'),
(413, 12, 33, 60.00, 10.00, '2026-07-21 20:07:32', '2026-07-21 20:08:25'),
(414, 12, 31, 60.00, 10.00, '2026-07-21 20:07:32', '2026-07-21 20:08:25'),
(415, 12, 30, 60.00, 10.00, '2026-07-21 20:07:32', '2026-07-21 20:08:25'),
(419, 13, 29, 20.00, 20.00, '2026-07-21 20:09:56', '2026-07-21 20:09:56'),
(420, 13, 28, 20.00, 20.00, '2026-07-21 20:09:56', '2026-07-21 20:09:56');

-- --------------------------------------------------------

--
-- Table structure for table `therapist_deductions`
--

CREATE TABLE `therapist_deductions` (
  `id` int(11) UNSIGNED NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `deduction_date` date NOT NULL,
  `type` enum('ca','expense') NOT NULL DEFAULT 'ca' COMMENT 'ca = Cash Advance, expense = personal item',
  `label` varchar(100) NOT NULL DEFAULT '',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `therapist_ratings`
--

CREATE TABLE `therapist_ratings` (
  `id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `therapist_specialties`
--

CREATE TABLE `therapist_specialties` (
  `id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `therapist_specialties`
--

INSERT INTO `therapist_specialties` (`id`, `therapist_id`, `category_id`, `created_at`) VALUES
(30, 8, 30, '2026-06-29 06:49:26'),
(31, 8, 24, '2026-06-29 06:49:26'),
(32, 8, 3, '2026-06-29 06:49:26'),
(33, 8, 27, '2026-06-29 06:49:26'),
(34, 8, 22, '2026-06-29 06:49:26'),
(35, 8, 17, '2026-06-29 06:49:26'),
(36, 8, 28, '2026-06-29 06:49:26'),
(37, 8, 1, '2026-06-29 06:49:26'),
(38, 8, 13, '2026-06-29 06:49:26'),
(39, 8, 20, '2026-06-29 06:49:26'),
(40, 8, 19, '2026-06-29 06:49:26'),
(41, 8, 29, '2026-06-29 06:49:26'),
(42, 11, 30, '2026-07-15 14:48:22'),
(43, 11, 24, '2026-07-15 14:48:22'),
(44, 11, 3, '2026-07-15 14:48:22'),
(45, 11, 27, '2026-07-15 14:48:22'),
(46, 11, 1, '2026-07-15 14:48:22'),
(47, 12, 27, '2026-07-21 12:06:50'),
(48, 13, 24, '2026-07-21 12:09:11');

-- --------------------------------------------------------

--
-- Table structure for table `therapist_specialty_services`
--

CREATE TABLE `therapist_specialty_services` (
  `id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `therapist_specialty_services`
--

INSERT INTO `therapist_specialty_services` (`id`, `therapist_id`, `service_id`) VALUES
(129, 8, 5),
(127, 8, 6),
(128, 8, 7),
(130, 8, 8),
(131, 8, 9),
(132, 8, 10),
(134, 8, 11),
(133, 8, 12),
(117, 8, 13),
(116, 8, 14),
(121, 8, 15),
(118, 8, 16),
(119, 8, 17),
(120, 8, 18),
(109, 8, 19),
(110, 8, 20),
(108, 8, 21),
(138, 8, 22),
(137, 8, 23),
(135, 8, 24),
(136, 8, 25),
(114, 8, 26),
(115, 8, 27),
(107, 8, 28),
(106, 8, 29),
(113, 8, 30),
(112, 8, 31),
(139, 8, 32),
(111, 8, 33),
(124, 8, 34),
(125, 8, 35),
(122, 8, 36),
(123, 8, 37),
(126, 8, 38),
(104, 8, 39),
(103, 8, 40),
(146, 8, 42),
(141, 8, 43),
(143, 8, 44),
(145, 8, 45),
(144, 8, 46),
(140, 8, 47),
(205, 11, 5),
(203, 11, 6),
(204, 11, 7),
(206, 11, 8),
(207, 11, 9),
(208, 11, 10),
(209, 11, 11),
(197, 11, 19),
(198, 11, 20),
(196, 11, 21),
(202, 11, 26),
(195, 11, 28),
(194, 11, 29),
(201, 11, 30),
(200, 11, 31),
(199, 11, 33),
(192, 11, 39),
(191, 11, 40),
(229, 12, 30),
(228, 12, 31),
(227, 12, 33),
(234, 13, 28),
(233, 13, 29);

-- --------------------------------------------------------

--
-- Table structure for table `unpaids_corp`
--

CREATE TABLE `unpaids_corp` (
  `id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `client_name` varchar(120) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `series` varchar(50) DEFAULT NULL COMMENT 'Invoice or series reference',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Corporate or institutional clients with unpaid balances logged per day';

--
-- Dumping data for table `unpaids_corp`
--

INSERT INTO `unpaids_corp` (`id`, `report_date`, `client_name`, `amount`, `series`, `notes`, `created_by`, `created_at`) VALUES
(1, '2026-08-28', 'Iqor', 1000.00, '', NULL, 2, '2026-08-28 12:17:36');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `role` enum('user','admin','therapist') NOT NULL DEFAULT 'user',
  `admin_role` enum('owner','cashier','marketing','it') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `cashier_pin` char(4) DEFAULT NULL COMMENT '4-digit PIN for cashier action accountability',
  `session_token` varchar(64) DEFAULT NULL COMMENT 'Active session token for single-session enforcement',
  `session_started` datetime DEFAULT NULL COMMENT 'When the current session started'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `full_name`, `phone`, `address`, `role`, `admin_role`, `created_at`, `deleted_at`, `cashier_pin`, `session_token`, `session_started`) VALUES
(2, 'adminuser', '$2y$10$WCZgq3V29DnGkJVfrYUJtuhCbRBRavxmyZh.FdS3O37rW5dUALOl.', 'admin@user.com', 'admin Wellness', '123', 'none', 'admin', 'owner', '2026-03-15 08:43:40', NULL, NULL, NULL, NULL),
(6, 'admin', '$2y$10$iY1cPcry5Zsq4pTiEBvgfuUHUh1HDFmIEO7TZ5J/9tiplvU2Ct1UO', 'admin@spa.com', 'System Administrator', '09000000000', 'Admin Office', 'admin', 'owner', '2026-03-22 08:00:05', NULL, NULL, NULL, NULL),
(42, 'walkin_customer', 'N/A', 'walkin@spa.com', 'Walk-in Customer', 'N/A', 'Walk-in Customer', 'user', NULL, '2026-07-22 08:48:56', NULL, NULL, NULL, NULL),
(43, 'keanne', '$2y$10$TBUTXR9ph1tDUpcM/2nmJOSo/4GYxRK0Dhhq9M69DuRkceDL./Am.', 'Keer.gonzalez.ui@phinmaed.com', 'Gonzalez', '09483941263', 'Quezon Arevalo', 'user', NULL, '2026-07-28 05:55:48', NULL, NULL, NULL, NULL),
(44, 'fenny', '$2y$10$ymHmajuZZblk7hs/qS8wrOUpLNa7Q7Rzcg5iitZtawUXsek9xwcUO', 'maba.guzman.ui@phinmaed.com', 'Ma. fenny b. guzman', '09483941263', 'quezon 123\r\n123', 'user', NULL, '2026-07-28 06:13:56', NULL, NULL, NULL, NULL),
(45, 'Gabriel', '$2y$10$Q.CaXf0JwHDjGkR6hmwrluISqhS271vIAHWLQ/XpjEpO/QvIUq43.', 'cjob.candaganan.ui@phinmaed.com', 'GabrielCandaganan', '09153383878', 'Iloilo City', 'user', NULL, '2026-08-09 11:26:37', NULL, NULL, NULL, NULL),
(46, 'yana', '$2y$10$zqFmhqWlPMHhB8TVvOMa4eYjVCpN7vOapLSl5tNlND3EpXNQb61cy', 'alyanahdaleestillore12@gmail.com', 'Alyanah Dale Estillore', '09999742501', 'simon ledesma', 'user', NULL, '2026-08-09 16:41:27', NULL, NULL, NULL, NULL),
(47, 'alyanah', '$2y$10$zKOCBrjRRJpOAqrrXoQrguOJsAt8vqVB4k0r/g3pMoGxh4rDlr4Wi', 'admin@recovery.spa', 'Receptionist', '09999999999', 'Admin Office', 'admin', 'cashier', '2026-08-15 02:19:38', NULL, NULL, '689a3b8b8c67dd3b223706043d7ef4ea31931624bbf8294ebd2bf7a0d698bef3', '2026-08-16 12:34:44');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_actor` (`actor_id`),
  ADD KEY `idx_action` (`action_type`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `order_item_id` (`order_item_id`),
  ADD KEY `idx_appt_therapist_date` (`therapist_id`,`appointment_date`),
  ADD KEY `idx_appt_user_id` (`user_id`),
  ADD KEY `idx_appt_status` (`status`),
  ADD KEY `idx_appt_date` (`appointment_date`);

--
-- Indexes for table `appointment_extra_services`
--
ALTER TABLE `appointment_extra_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aes_appointment` (`appointment_id`);

--
-- Indexes for table `appointment_therapists`
--
ALTER TABLE `appointment_therapists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_appt_therapist` (`appointment_id`,`therapist_id`),
  ADD KEY `idx_appt_therapist_therapist` (`therapist_id`),
  ADD KEY `idx_appt_therapist_appt` (`appointment_id`);

--
-- Indexes for table `business_expenses`
--
ALTER TABLE `business_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_biz_date` (`expense_date`),
  ADD KEY `idx_biz_category` (`category`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_product` (`user_id`,`product_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_cart_user_id` (`user_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `daily_product_sales`
--
ALTER TABLE `daily_product_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dps_date` (`report_date`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `daily_reports`
--
ALTER TABLE `daily_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_report_date` (`report_date`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_dr_report_date` (`report_date`);

--
-- Indexes for table `daily_report_denominations`
--
ALTER TABLE `daily_report_denominations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_report_denom` (`report_id`,`denomination`);

--
-- Indexes for table `daily_report_manual_entries`
--
ALTER TABLE `daily_report_manual_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rme_date` (`report_date`);

--
-- Indexes for table `daily_report_spreadsheet_rows`
--
ALTER TABLE `daily_report_spreadsheet_rows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_drsr_date` (`report_date`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_fb_appointment` (`appointment_id`),
  ADD UNIQUE KEY `ux_fb_order` (`order_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `gift_certificates`
--
ALTER TABLE `gift_certificates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gc_date` (`report_date`),
  ADD KEY `idx_gc_type` (`type`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user_id` (`user_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orders_user_id` (`user_id`),
  ADD KEY `idx_orders_payment_status` (`payment_status`),
  ADD KEY `idx_orders_paymongo_link` (`paymongo_link_id`(50));

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `idx_oi_order_id` (`order_id`);

--
-- Indexes for table `partners`
--
ALTER TABLE `partners`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `partner_rates`
--
ALTER TABLE `partner_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_partner_service` (`partner_id`,`service_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `receptionist_pins`
--
ALTER TABLE `receptionist_pins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rpin` (`pin`);

--
-- Indexes for table `refund_requests`
--
ALTER TABLE `refund_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rr_appointment` (`appointment_id`),
  ADD KEY `idx_rr_status` (`status`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `therapists`
--
ALTER TABLE `therapists`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_therapist_user_id` (`user_id`);

--
-- Indexes for table `therapist_attendance`
--
ALTER TABLE `therapist_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`therapist_id`,`duty_date`);

--
-- Indexes for table `therapist_commission`
--
ALTER TABLE `therapist_commission`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_therapist_service` (`therapist_id`,`service_id`),
  ADD KEY `fk_tc_service` (`service_id`);

--
-- Indexes for table `therapist_deductions`
--
ALTER TABLE `therapist_deductions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ded_therapist_date` (`therapist_id`,`deduction_date`);

--
-- Indexes for table `therapist_ratings`
--
ALTER TABLE `therapist_ratings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_therapist_appt_user` (`therapist_id`,`appointment_id`,`user_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `therapist_specialties`
--
ALTER TABLE `therapist_specialties`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_therapist_category` (`therapist_id`,`category_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `therapist_specialty_services`
--
ALTER TABLE `therapist_specialty_services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_th_svc` (`therapist_id`,`service_id`);

--
-- Indexes for table `unpaids_corp`
--
ALTER TABLE `unpaids_corp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_unpaid_date` (`report_date`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `appointment_extra_services`
--
ALTER TABLE `appointment_extra_services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `appointment_therapists`
--
ALTER TABLE `appointment_therapists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `business_expenses`
--
ALTER TABLE `business_expenses`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `daily_product_sales`
--
ALTER TABLE `daily_product_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `daily_reports`
--
ALTER TABLE `daily_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `daily_report_denominations`
--
ALTER TABLE `daily_report_denominations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `daily_report_manual_entries`
--
ALTER TABLE `daily_report_manual_entries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `daily_report_spreadsheet_rows`
--
ALTER TABLE `daily_report_spreadsheet_rows`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gift_certificates`
--
ALTER TABLE `gift_certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `partners`
--
ALTER TABLE `partners`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `partner_rates`
--
ALTER TABLE `partner_rates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `receptionist_pins`
--
ALTER TABLE `receptionist_pins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `refund_requests`
--
ALTER TABLE `refund_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `therapists`
--
ALTER TABLE `therapists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `therapist_attendance`
--
ALTER TABLE `therapist_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `therapist_commission`
--
ALTER TABLE `therapist_commission`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=421;

--
-- AUTO_INCREMENT for table `therapist_deductions`
--
ALTER TABLE `therapist_deductions`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `therapist_ratings`
--
ALTER TABLE `therapist_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `therapist_specialties`
--
ALTER TABLE `therapist_specialties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `therapist_specialty_services`
--
ALTER TABLE `therapist_specialty_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=237;

--
-- AUTO_INCREMENT for table `unpaids_corp`
--
ALTER TABLE `unpaids_corp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appt_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `appointment_therapists`
--
ALTER TABLE `appointment_therapists`
  ADD CONSTRAINT `appointment_therapists_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointment_therapists_ibfk_2` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `daily_product_sales`
--
ALTER TABLE `daily_product_sales`
  ADD CONSTRAINT `daily_product_sales_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `daily_reports`
--
ALTER TABLE `daily_reports`
  ADD CONSTRAINT `daily_reports_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `daily_report_denominations`
--
ALTER TABLE `daily_report_denominations`
  ADD CONSTRAINT `daily_report_denominations_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `daily_reports` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feedback_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `feedback_ibfk_3` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `gift_certificates`
--
ALTER TABLE `gift_certificates`
  ADD CONSTRAINT `gift_certificates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `order_items_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `partner_rates`
--
ALTER TABLE `partner_rates`
  ADD CONSTRAINT `fk_pr_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `services_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `therapist_attendance`
--
ALTER TABLE `therapist_attendance`
  ADD CONSTRAINT `fk_attendance_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `therapist_commission`
--
ALTER TABLE `therapist_commission`
  ADD CONSTRAINT `fk_tc_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tc_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `therapist_deductions`
--
ALTER TABLE `therapist_deductions`
  ADD CONSTRAINT `fk_ded_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `therapist_ratings`
--
ALTER TABLE `therapist_ratings`
  ADD CONSTRAINT `therapist_ratings_ibfk_1` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `therapist_ratings_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `therapist_ratings_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `therapist_specialties`
--
ALTER TABLE `therapist_specialties`
  ADD CONSTRAINT `therapist_specialties_ibfk_1` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `therapist_specialties_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `unpaids_corp`
--
ALTER TABLE `unpaids_corp`
  ADD CONSTRAINT `unpaids_corp_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
