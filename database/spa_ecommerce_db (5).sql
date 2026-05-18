-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 18, 2026 at 07:14 PM
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
  `updated_by_name` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `user_id`, `service_id`, `order_item_id`, `appointment_date`, `status`, `cancel_reason`, `approved_by`, `approved_by_name`, `people_count`, `created_at`, `service_type`, `rate_type`, `partner_id`, `charged_price`, `home_address`, `home_notes`, `customer_note`, `therapist_id`, `duration_minutes`, `updated_by_name`) VALUES
(3, 1, 6, NULL, '2026-03-30 13:51:00', 'approved', NULL, NULL, NULL, 1, '2026-03-30 02:52:45', 'onsite', 'regular', NULL, 0.00, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 27, 29, 30, '2026-05-20 11:54:00', 'completed', NULL, 2, 'admin Wellness', 2, '2026-05-18 10:56:21', '', 'regular', NULL, 0.00, '', '', '', NULL, NULL, 'admin Wellness');

-- --------------------------------------------------------

--
-- Table structure for table `appointment_extra_services`
--

CREATE TABLE `appointment_extra_services` (
  `id` int(10) UNSIGNED NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `person_label` varchar(50) NOT NULL DEFAULT 'Person 1' COMMENT 'e.g. Person 1, Person 2',
  `charged_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rate_type` enum('regular','home','hotel','influencer') NOT NULL DEFAULT 'regular',
  `payment_method` enum('cash','card','qrph') NOT NULL DEFAULT 'cash',
  `payment_status` enum('paid','unpaid') NOT NULL DEFAULT 'paid',
  `notes` text DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL COMMENT 'admin user_id who added it',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `commission` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointment_therapists`
--

INSERT INTO `appointment_therapists` (`id`, `appointment_id`, `therapist_id`, `assigned_at`, `notes`, `commission`) VALUES
(10, 19, 4, '2026-05-18 18:57:28', '', 0.00),
(11, 19, 1, '2026-05-18 18:58:02', '', 0.00);

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
(30, 'Additional in Massage Service', 'service', '2026-03-24 18:28:36');

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

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`id`, `user_id`, `order_id`, `appointment_id`, `rating`, `comment`, `created_at`) VALUES
(2, 27, 31, 19, 5, 'Monayan', '2026-05-18 11:01:05');

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
(2, NULL, 'order', '💳 New Online Payment', 'Order #1 has been paid online and is awaiting your approval.', 'orders.php', 1, '2026-03-29 02:43:50'),
(6, NULL, 'order', '💳 New Online Payment', 'Order #3 has been paid online and is awaiting your approval.', 'orders.php', 1, '2026-03-29 20:49:42'),
(10, NULL, 'order', '💳 New Online Payment', 'Order #4 has been paid online and is awaiting your approval.', 'orders.php', 1, '2026-03-29 20:58:46'),
(14, NULL, 'order', '🛍️ New Product Order', 'A new product order #5 (₱259.00) has been received.', 'orders.php', 1, '2026-03-29 20:59:37'),
(18, NULL, 'order', '🛍️ New Product Order', 'A new product order #6 (₱259.00) has been received.', 'orders.php', 1, '2026-03-29 21:05:39'),
(22, NULL, 'order', '🛍️ New Product Order', 'A new product order #7 (₱259.00) has been received.', 'orders.php', 1, '2026-03-29 21:32:58'),
(26, NULL, 'order', '🛍️ New Product Order', 'A new product order #8 (₱259.00) has been received.', 'orders.php', 1, '2026-03-29 21:40:48'),
(30, NULL, 'order', '🛍️ New Product Order', 'A new product order #9 (₱259.00) has been received.', 'orders.php', 1, '2026-03-29 22:02:54'),
(32, NULL, 'appointment', '📅 New Service Booking', 'A new appointment booking #10 has been placed by a customer.', 'appointments.php', 1, '2026-03-29 22:03:26'),
(34, NULL, 'order', '💳 New Online Payment', 'Order #11 has been paid online and is awaiting your approval.', 'orders.php', 1, '2026-03-29 22:04:01'),
(40, NULL, 'appointment', '📅 New Service Booking', 'A new appointment booking #13 has been placed by a customer.', 'appointments.php', 1, '2026-03-30 01:33:41'),
(44, NULL, 'appointment', '📅 New Service Booking', 'A new appointment booking #17 has been placed by a customer.', 'appointments.php', 1, '2026-05-12 09:43:27'),
(48, NULL, 'appointment', '💳 New Online Payment', 'Order #20 (₱949.00) has been paid online and is awaiting your approval.', 'appointments.php', 1, '2026-05-12 09:55:14'),
(50, NULL, 'appointment', '💳 New Online Payment', 'Order #22 (₱499.00) has been paid online.', 'appointments.php', 1, '2026-05-12 10:08:04'),
(52, NULL, 'appointment', '💳 New Online Payment', 'Order #23 (₱949.00) paid online. Ref: pay_3KkaYWMXt14A2HuSJWRFxmHw', 'appointments.php', 1, '2026-05-12 10:18:00'),
(57, NULL, 'appointment', '💳 New Online Payment', 'Order #24 (₱499.00) paid online. Ref: pay_BLvUi1TexYsbWvFCAtsvJ2dH', 'appointments.php', 1, '2026-05-14 06:15:05'),
(61, NULL, 'appointment', '📅 New Service Booking', 'A new appointment booking #25 has been placed by a customer.', 'appointments.php', 1, '2026-05-14 09:33:42'),
(65, NULL, 'appointment', '📅 New Service Booking', 'A new appointment booking #26 has been placed by a customer.', 'appointments.php', 1, '2026-05-14 12:15:46'),
(68, NULL, 'appointment', '💳 New Online Payment', 'Order #27 (₱949.00) paid online. Ref: pay_w4bQjAmxVHHQS42KfDHRgdwW', 'appointments.php', 1, '2026-05-15 08:19:30'),
(71, NULL, 'appointment', '💳 New Online Payment', 'Order #28 (₱949.00) paid online. Ref: pay_4dNZXDhXYsoRqQwgSXrFEgbo', 'appointments.php', 1, '2026-05-15 08:26:13'),
(75, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #29 by a customer.', 'appointments.php', 1, '2026-05-15 09:28:54'),
(77, NULL, 'appointment', '📅 New Service Booking', 'New appointment booking #30 by a customer.', 'appointments.php', 1, '2026-05-15 09:45:39'),
(83, 27, 'order', '💳 Payment Confirmed!', 'Your online payment for order #31 was successful. Awaiting admin approval.', 'appointments.php#orders', 0, '2026-05-18 10:56:44'),
(84, NULL, 'order', '💳 New Online Payment', 'Order #31 (₱649.00) paid online. Ref: pay_NNdebzpJTPvP4M2iHQgrbCQw', 'orders.php', 0, '2026-05-18 10:56:44'),
(85, 27, 'appointment', '✅ Appointment Approved!', 'Your Coffee Detox Scrub appointment has been approved and a therapist has been assigned. You may now proceed to the spa!', 'appointments.php', 0, '2026-05-18 10:58:10'),
(86, 27, 'appointment', '📅 Appointment Rescheduled', 'Your appointment has been rescheduled to May 20, 2026 11:54 AM.', 'appointments.php', 0, '2026-05-18 10:59:05'),
(87, 27, 'appointment', '🎉 Session Completed!', 'Your Coffee Detox Scrub session is done. Thank you for visiting! Please leave a feedback.', 'appointments.php', 0, '2026-05-18 11:00:04');

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
  `payment_method` varchar(20) DEFAULT 'cash',
  `payment_status` enum('pending_payment','unpaid','paid','refunded','partially_refunded') NOT NULL DEFAULT 'unpaid',
  `paymongo_link_id` varchar(100) DEFAULT NULL,
  `paymongo_reference` varchar(100) DEFAULT NULL,
  `paymongo_payment_id` varchar(100) DEFAULT NULL COMMENT 'pay_xxx ID from PayMongo — needed for refunds',
  `approval_status` enum('pending','approved','declined','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `customer_name`, `customer_name_snapshot`, `email`, `phone`, `address`, `booking_date`, `total_amount`, `payment_method`, `payment_status`, `paymongo_link_id`, `paymongo_reference`, `paymongo_payment_id`, `approval_status`, `approved_by`, `approved_by_name`, `created_at`) VALUES
(14, 1, 'kent clarence gonzalez', NULL, '', '0948361273', '', '2026-03-30 13:51:00', 239.00, 'cash', 'paid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-03-30 02:52:45'),
(15, 1, 'kent clarence gonzalez', NULL, '', '0957262641', '', NULL, 159.00, 'cash', 'paid', NULL, NULL, NULL, 'approved', NULL, NULL, '2026-03-30 02:57:41'),
(31, 27, 'Gabriel Candaganan', NULL, 'cjob.candaganan.ui@phinmaed.com', '09123456789', 'Iloilo City', '2026-05-19 11:54:00', 649.00, 'online', 'paid', 'cs_76b91413284cf93a6f4943c7', 'pay_NNdebzpJTPvP4M2iHQgrbCQw', NULL, 'completed', 2, 'admin Wellness', '2026-05-18 10:56:21');

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
(14, 15, 7, NULL, 1, 159.00, 159.00, 0.00),
(30, 31, NULL, 29, 1, 649.00, 649.00, 0.00);

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
(1, 'Hotel Partner', 'hotel', NULL, NULL, 'active', '2026-05-15 16:54:27');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `category_id`, `image`, `description`, `price`, `stock`, `created_at`) VALUES
(5, 'Organic Shampoo - 100ML', 9, 'product_1774724380_Shampoo.png', 'The Ultimate Hair Detox.\r\nInfused with 100% natural coconut milk, this duo restores silky softness and radiant shine to tired strands. Organic, nutrient-rich, and designed to bring your hair back to life\r\n\r\n100ML', 149.00, 20, '2026-03-28 18:32:48'),
(6, 'Organinc Shampoo - 200 ML', 9, 'product_1774724368_Shampoo.png', 'The Ultimate Hair Detox.\r\nInfused with 100% natural coconut milk, this duo restores silky softness and radiant shine to tired strands. Organic, nutrient-rich, and designed to bring your hair back to life.\r\n\r\n200ML', 249.00, 20, '2026-03-28 18:40:31'),
(7, 'Conditioner - 100 ML', 9, 'product_1774724522_Conditioner.png', 'The Ultimate Hair Detox.\r\nInfused with 100% natural coconut milk, this duo restores silky softness and radiant shine to tired strands. Organic, nutrient-rich, and designed to bring your hair back to life.', 159.00, 19, '2026-03-28 19:02:02'),
(8, 'Conditioner - 200ML', 9, 'product_1774724567_Conditioner.png', 'The Ultimate Hair Detox.\r\nInfused with 100% natural coconut milk, this duo restores silky softness and radiant shine to tired strands. Organic, nutrient-rich, and designed to bring your hair back to life.\r\n200ML', 259.00, 10, '2026-03-28 19:02:47'),
(9, 'Hand Sanitizer', 9, 'product_1774724844_Hand-Sanitizer.jpg', 'Experience premium hygiene with our SCENTSMITH Hand Sanitizer – Bergamot Patchouli (200ml). Crafted with a refreshing blend of citrusy bergamot and earthy patchouli, this sanitizer not only eliminates germs effectively but also leaves a long-lasting, sophisticated scent on your hands.', 250.00, 20, '2026-03-28 19:07:24'),
(10, 'The Pamper Set (Holiday Gift Bag) The Gift of Self-Care.', 9, 'product_1774724911_657444589_1554107539023078_2006236898953284170_n.jpg', 'The Pamper Set (Holiday Gift Bag)\r\nThe Gift of Self-Care.\r\nEverything you need for a spa day at home. This 5-piece set includes a massage oil, body scrub, face towel, shampoo, and conditioner—all wrapped up and ready to give.', 499.00, 20, '2026-03-28 19:08:31'),
(11, 'Soothing Massage Oils', 7, 'product_1774725098_656854241_2424585127993047_6672032975636080307_n.jpg', 'Your Pocket-Sized Escape. Designed for deep relaxation and skin hydration in every 100ml bottle.', 119.00, 20, '2026-03-28 19:11:38'),
(12, 'Milk &amp; Honey Body Scrub', 8, 'product_1774725266_ChatGPT Image Mar 29, 2026, 03_13_15 AM.png', 'Glow with Every Scrub. This revitalizing body scrub is formulated to gently exfoliate, detoxify, and nourish your skin. Infused with the energizing aroma of coffee and the soothing blend of honey and milk, it helps remove dead skin cells while leaving your skin soft, smooth, and naturally radiant.', 175.00, 200, '2026-03-28 19:14:26');

-- --------------------------------------------------------

--
-- Table structure for table `receptionists`
--

CREATE TABLE `receptionists` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `pin` varchar(4) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

--
-- Dumping data for table `refund_requests`
--

INSERT INTO `refund_requests` (`id`, `appointment_id`, `order_id`, `user_id`, `amount`, `reason`, `status`, `paymongo_payment_id`, `paymongo_refund_id`, `refund_notes`, `processed_by`, `created_at`, `updated_at`) VALUES
(1, 15, 27, 20, 949.00, 'unavailable', 'refunded', 'pay_w4bQjAmxVHHQS42KfDHRgdwW', 'ref_S85KYJzmNvY5eVpcnf7ZRGYE', '', 2, '2026-05-15 16:23:59', '2026-05-15 16:30:23'),
(2, 16, 28, 20, 949.00, 'unavaiable', 'refunded', 'pay_4dNZXDhXYsoRqQwgSXrFEgbo', 'ref_yVFiMcUjo8TXvdLuQpByWBsA', '', 2, '2026-05-15 16:27:57', '2026-05-16 15:31:48');

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
  `home_service_fee` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `name`, `category_id`, `image`, `description`, `price`, `session_time`, `created_at`, `is_home_service`, `home_service_fee`) VALUES
(5, 'Cleaning', 1, 'service_1774366363_Nail Care - Cleaning.jpg', 'Treat your hands and feet to a refreshing Nail Care – Cleaning service. We carefully cleanse, trim, and shape your nails while gently caring for your cuticles, leaving them feeling light, clean, and rejuvenated. A perfect way to relax while keeping your nails naturally beautiful.', 209.00, 30, '2026-03-24 15:32:43', 0, 0.00),
(6, 'Basic Manicure', 1, 'service_1774366433_Nail Care - Basic Manicure.jpg', 'Enjoy a refreshing Basic Manicure that combines nail care with relaxation. Your nails will be carefully shaped and cleaned, cuticles gently treated, followed by a soothing hand massage and a beautiful polish of your choice.', 239.00, 30, '2026-03-24 15:33:53', 0, 0.00),
(7, 'Basic Pedicure', 1, 'service_1774366470_Nail Care - Basic Pedicure.jpg', 'Give your feet the care they deserve with our Basic Pedicure. Enjoy thorough nail cleaning, shaping, and cuticle treatment, followed by a soothing foot massage and a polish application that leaves your feet feeling fresh, soft, and revitalized.', 289.00, 30, '2026-03-24 15:34:30', 0, 0.00),
(8, 'Gel Manicure', 1, 'service_1774366506_Nail Care - Gel Manicure.jpg', 'Treat yourself to a Gel Manicure that combines nail care and beauty. Your nails will be shaped and cleaned, cuticles gently treated, and finished with a beautiful gel polish that stays shiny and chip-resistant for weeks.', 399.00, 30, '2026-03-24 15:35:06', 0, 0.00),
(9, 'Gel Pedicure', NULL, 'service_1774366560_Nail Care - Gel Pedicure.jpg', 'Pamper your feet with our Gel Pedicure. Enjoy thorough nail cleaning, shaping, and cuticle care, followed by a soothing foot massage and a beautiful gel polish that stays vibrant, smooth, and shiny for weeks.', 499.00, 30, '2026-03-24 15:36:00', 0, 0.00),
(10, 'Gel Removal', 1, 'service_1774367661_Nail Care - Gel removal.jpg', 'Remove old gel polish with care through our Gel Removal service. Designed to keep your natural nails strong and healthy while effectively taking off gel products.', 499.00, 30, '2026-03-24 15:54:21', 0, 0.00),
(11, 'Simple', 13, 'service_1774368320_Nail Extension - Simple.jpg', 'Enhance your nails with our Simple Nail Extension service. Designed to give added length and shape while keeping a soft, natural appearance that’s perfect for everyday wear.', 999.00, 30, '2026-03-24 16:05:20', 0, 0.00),
(12, 'Personal Design', 13, 'service_1774368557_Nail Extension - Personal Extension.jpg', 'Create nails that match your personality with our Personal Design service. Choose your own colors, patterns, and styles for a one-of-a-kind look made just for you.', 1500.00, 30, '2026-03-24 16:09:17', 0, 0.00),
(13, 'Classic', 17, 'service_1774368742_Lash Services - Classic.jpg', 'Achieve effortlessly beautiful eyes with our Classic Lash Service. This technique provides a light, natural enhancement by adding length and subtle volume, giving you a fresh and elegant appearance.', 699.00, 30, '2026-03-24 16:12:22', 0, 0.00),
(14, 'Cat Eye / Wispy', 17, 'service_1774368971_Lash Services - Cat Eye or Wispy.jpg', 'Achieve a flirty and textured look with our Cat Eye / Wispy Lashes. Designed with varying lash lengths, this style adds dimension and a natural wispy effect while creating a subtle winged shape for a more defined, glamorous appearance.', 799.00, 30, '2026-03-24 16:16:11', 0, 0.00),
(15, 'Semi  Glam', 17, 'service_1774369063_Lash Services - Semi Glam.jpg', 'Enhance your beauty with our Semi Glam Lashes. This set provides a fuller, fluffier appearance than classic lashes, giving your eyes a soft glam effect that’s perfect for both everyday wear and special occasions.', 899.00, 30, '2026-03-24 16:17:43', 0, 0.00),
(16, 'Full Glam', 17, 'service_1774369141_Lash Services - Full Glam.jpg', 'Turn heads with our Full Glam Lashes. Designed for ultimate fullness, this set adds intense volume and definition, giving your eyes a rich, dramatic effect that enhances any makeup look.', 999.00, 30, '2026-03-24 16:19:01', 0, 0.00),
(17, 'Lash lift', 17, 'service_1774369197_Lash Services - Lash Lift.jpg', 'A treatment that lifts and curls your natural lashes, giving them a longer and more defined appearance without the use of extensions.', 490.00, 30, '2026-03-24 16:19:57', 0, 0.00),
(18, 'Lash Removal', 17, 'service_1774369343_Lash Services - Lash Removal.jpg', 'Safely remove old or unwanted lash extensions with our Lash Removal treatment. We use gentle techniques to ensure your natural lashes stay healthy, strong, and ready for your next lash appointment.', 300.00, 30, '2026-03-24 16:22:23', 0, 0.00),
(19, 'Brow Shaping (Wax or Thread)', 3, 'service_1774369414_Brows Service - Brow Shaping.jpg', 'Perfectly shaped brows are just a treatment away. We use gentle and precise waxing or threading techniques to remove unwanted hair and create a polished, natural look that frames your eyes beautifully.', 269.00, 30, '2026-03-24 16:23:34', 0, 0.00),
(20, 'Brow Tinting', 3, 'service_1774369501_Brows Services - Brow Tinting.jpg', 'Achieve effortlessly beautiful brows with our tinting treatment. We carefully apply a semi-permanent tint to give your eyebrows a consistent, natural-looking shade that complements your features', 349.00, 30, '2026-03-24 16:25:01', 0, 0.00),
(21, 'Brow Lamination', 3, 'service_1774369546_Brows Services - Brow Lamination.jpg', 'Enhance your natural brows with our lamination treatment. This service restructures and sets your brow hairs in place, creating a soft, full, and perfectly styled look that lasts for weeks.', 799.00, 30, '2026-03-24 16:25:46', 0, 0.00),
(22, 'Brow Shaping + Brow Tinting', 19, 'service_1774370428_Packages - Brow Shaping + Brow Tinting.jpg', 'Enhance your natural brows with this all-in-one package. Includes expert shaping (wax or thread) and brow tinting to add depth, definition, and a more balanced appearance.', 599.00, 30, '2026-03-24 16:40:28', 0, 0.00),
(23, 'Brow Lamination + Tint', 19, 'service_1774370542_Packages - Brow Lamination + Tint.jpg', 'Enhance your brows with this all-in-one package. Brow lamination smooths and sets the hairs in place, while tinting adds richness and definition for a naturally fuller appearance.', 999.00, 300, '2026-03-24 16:42:22', 0, 0.00),
(24, 'Face Lifting Massage', 20, 'service_1774370849_Other Service - Face Lifting.jpg', 'Our Face Lifting service helps tighten and firm your facial muscles, promoting a lifted, youthful appearance. Ideal for enhancing skin elasticity and restoring a refreshed look.', 479.00, 30, '2026-03-24 16:47:29', 0, 0.00),
(25, 'Scalp Scrub', 20, 'service_1774370979_Other Service - Scalp Services.jpg', 'Our Scalp Scrub gently exfoliates and removes buildup, promoting a clean, healthy scalp. Ideal for revitalizing hair roots and improving scalp circulation.', 479.00, 30, '2026-03-24 16:49:39', 0, 0.00),
(26, 'Express Head Spa', 22, '0', 'Indulge in our Express Japanese Head Spa. This treatment detoxifies and revitalizes your scalp while providing a soothing massage that relaxes both the mind and body, leaving you refreshed in just a short session.', 1399.00, 45, '2026-03-24 17:05:48', 0, 0.00),
(27, 'Luxury Scalp and Basic Facial', 22, 'service_1774372280_Japanese Head Spa - Luxury Scalp and Basic Facial.jpg', 'Indulge in our Express Japanese Head Spa. This treatment detoxifies and revitalizes your scalp while providing a soothing massage that relaxes both the mind and body, leaving you refreshed in just a short session.', 2199.00, 30, '2026-03-24 17:11:20', 0, 0.00),
(28, 'Milk + Honey Scrub', 24, 'service_1774373090_Body Scrub - Milk + Honey Scrub.jpg', 'Indulge in a soothing Milk + Honey scrub that cleanses, hydrates, and revitalizes your skin, leaving it radiant and silky-soft.', 649.00, 30, '2026-03-24 17:24:50', 0, 0.00),
(29, 'Coffee Detox Scrub', 24, 'service_1774373785_Body Scrub - Coffee Detox Scrub.jpg', 'Our Coffee Detox Scrub exfoliates and detoxifies your skin while stimulating circulation, leaving your body feeling refreshed, smooth, and revitalized.', 649.00, 30, '2026-03-24 17:36:25', 0, 0.00),
(30, 'Regular Foot Spa', 27, 'service_1774374722_Foot Service - Regular Foot Spa.jpg', 'Our Regular Foot Spa soothes tired feet, cleanses, and softens the skin. Enjoy a calming soak and gentle massage that refreshes and revitalizes your feet.', 599.00, 30, '2026-03-24 17:52:02', 0, 0.00),
(31, 'Foot Spa + Foot Scrub', 27, 'service_1774375352_Foor Service - Foot spa + Foor scrub.jpg', 'Indulge in our Foot Spa + Foot Scrub. This luxurious treatment soothes, exfoliates, and nourishes your feet, leaving them soft, smooth, and completely revitalized.', 649.00, 30, '2026-03-24 18:02:32', 0, 0.00),
(32, 'Foot Spa + Foot Scrub + Foot Massage', 19, 'service_1774375721_Foor Servie - Foot Spa + Foot Scrub + Foot Massage.jpg', 'Indulge in our luxurious Foot Spa + Foot Scrub + Foot Massage. This all-in-one treatment softens, exfoliates, and massages your feet for ultimate comfort, relaxation, and rejuvenation.', 849.00, 30, '2026-03-24 18:08:41', 0, 0.00),
(33, 'Foot Reflex', 27, 'service_1774376036_Foot Service - Foot reflex.jpg', 'Indulge in our Foot Reflex service, a rejuvenating treatment that uses expert techniques to stimulate reflex points, promoting relaxation, better circulation, and overall well-being.', 399.00, 30, '2026-03-24 18:13:56', 0, 0.00),
(34, 'Swedish', 28, 'service_1774376289_Massage Service - Swedish.jpg', 'Indulge in our Swedish Massage, a soothing treatment that eases tension, improves circulation, and restores balance to both body and mind for a deeply relaxing experience.', 849.00, 30, '2026-03-24 18:18:09', 0, 0.00),
(35, 'Thai', NULL, 'service_1774376465_Massage Service - Thai.jpg', 'Experience a therapeutic Thai Massage that stretches and tones muscles, relieves stress, and restores balance to your body and mind.', 949.00, 30, '2026-03-24 18:21:05', 1, 0.00),
(36, 'Combination', 28, 'service_1774376615_Massage Service - Combination.jpg', 'Our Combination Massage blends different massage techniques tailored to your needs, offering muscle relaxation, tension relief, and overall rejuvenation in one session.', 849.00, 30, '2026-03-24 18:23:35', 0, 0.00),
(37, 'Shiatsu', 28, 'service_1774376822_Massage Service - Shiatsu.jpg', 'Experience a Japanese-inspired Shiatsu Massage that stimulates pressure points, relieves muscle stiffness, and promotes overall wellness and relaxation.', 949.00, 30, '2026-03-24 18:27:02', 0, 0.00),
(38, 'Ventosa', NULL, 'service_1774377305_Massage Service - Ventosa.jpg', 'Indulge in our Ventosa treatment, using therapeutic suction to release tight muscles, restore energy flow, and promote deep relaxation and wellness.', 300.00, 30, '2026-03-24 18:35:05', 0, 0.00),
(39, 'Hot Stone', 30, 'service_1774377382_Massage Service - Hot Stone.jpg', 'Experience a soothing massage with heated stones that melt away stress, ease muscle stiffness, and promote overall relaxation and wellness.', 300.00, 30, '2026-03-24 18:36:22', 0, 0.00),
(40, 'Ear Candling', 30, 'service_1774377488_Massage Service - Ear candling.jpg', 'Experience a safe and natural ear cleansing method that helps clear impurities, improves ear health, and provides a sense of calm and relief.', 250.00, 10, '2026-03-24 18:38:08', 0, 0.00),
(41, 'Hotel / Home Service', 30, 'service_1774377890_Massage Service - Hotel Home Service.jpg', 'Experience the ultimate convenience with our Hotel/Home Service Massage. We provide tailored massage treatments at your location for complete relaxation and comfort.', 300.00, 30, '2026-03-24 18:44:50', 0, 0.00),
(42, 'Under Arm', 29, 'service_1774378459_Waxing Service - Under arm.jpg', 'Experience a precise and gentle underarm waxing treatment that leaves your skin soft, smooth, and hair-free.', 359.00, 10, '2026-03-24 18:54:19', 0, 0.00),
(43, 'Chest', 29, 'service_1774378511_Waxing Service - Chest Wax.jpg', 'Experience a precise and gentle chest waxing treatment that leaves your skin smooth, hair-free, and refreshed.', 359.00, 10, '2026-03-24 18:55:11', 0, 0.00),
(44, 'Full Arm', 29, 'service_1774378711_Waxing Service - Full arm.jpg', 'Experience precise and gentle full arm waxing for clean, hair-free skin that feels refreshed and silky.', 799.00, 10, '2026-03-24 18:58:31', 0, 0.00),
(45, 'Half Arm', 29, 'service_1774378830_Waxing Service - half arm.jpg', 'Our Half Arm Waxing service removes unwanted hair from the elbow to the wrist (or shoulder to elbow), leaving your arms soft, smooth, and well-groomed.', 399.00, 10, '2026-03-24 19:00:30', 0, 0.00),
(46, 'Full Leg', 29, 'service_1774378889_Waxing Service - Full leg.jpg', 'Our Full Leg Waxing service removes unwanted hair from thigh to ankle, leaving your legs soft, smooth, and beautifully groomed.', 1079.00, 10, '2026-03-24 19:01:29', 0, 0.00),
(47, 'Bikini', 29, 'service_1774378935_Waxing Service - Bikini.jpg', 'Our Bikini Waxing service removes unwanted hair for a neat, clean, and well-groomed bikini area.', 839.00, 10, '2026-03-24 19:02:15', 0, 0.00),
(48, 'Chin', 29, 'service_1774378986_Waxing Service - Chin.jpg', 'Our Chin Waxing service gently removes unwanted hair for a smooth, clean, and well-groomed look.', 359.00, 10, '2026-03-24 19:03:06', 0, 0.00);

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `therapists`
--

INSERT INTO `therapists` (`id`, `user_id`, `full_name`, `phone`, `specialties`, `created_at`) VALUES
(1, 19, 'kent clarence gonzalez', '09999999999', '', '2026-03-25 20:53:29'),
(4, NULL, 'kent Clarence E. Gonzalez', '09999999999', 'Massage', '2026-03-25 21:36:50'),
(5, NULL, '123', '123', '123', '2026-03-25 22:00:00');

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
(2, 4, '2026-03-26', '05:36:00', NULL, 0.00, NULL, '2026-03-25 21:36:50', 1, 0),
(3, 5, '2026-03-26', '00:32:00', NULL, 0.00, NULL, '2026-03-25 22:00:00', 2, 0),
(4, 1, '2026-05-14', '13:33:00', NULL, 0.00, NULL, '2026-05-14 05:35:14', 3, 0),
(5, 4, '2026-05-15', '16:26:00', NULL, 0.00, NULL, '2026-05-15 08:26:55', 1, 0),
(6, 4, '2026-05-16', '15:10:00', NULL, 0.00, NULL, '2026-05-16 07:10:42', 1, 0),
(7, 1, '2026-05-16', '15:33:00', NULL, 0.00, NULL, '2026-05-16 07:33:05', 2, 0),
(8, 4, '2026-05-17', '18:52:00', NULL, 0.00, NULL, '2026-05-17 10:53:01', 2, 0),
(9, 1, '2026-05-17', '18:53:00', NULL, 0.00, NULL, '2026-05-17 10:53:04', 1, 0),
(10, 4, '2026-05-18', '18:57:00', NULL, 0.00, NULL, '2026-05-18 10:57:20', 4, 0),
(11, 1, '2026-05-18', '18:57:00', NULL, 0.00, NULL, '2026-05-18 10:57:55', 3, 0);

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

--
-- Dumping data for table `therapist_ratings`
--

INSERT INTO `therapist_ratings` (`id`, `therapist_id`, `appointment_id`, `user_id`, `rating`, `comment`, `created_at`) VALUES
(1, 4, 19, 27, 5, 'Sarap', '2026-05-18 11:01:05'),
(2, 1, 19, 27, 5, 'Sarap', '2026-05-18 11:01:05');

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
(1, 1, 30, '2026-05-14 05:35:13'),
(2, 1, 24, '2026-05-14 05:35:14'),
(3, 1, 6, '2026-05-14 05:35:14'),
(4, 1, 3, '2026-05-14 05:35:14'),
(5, 1, 5, '2026-05-14 05:35:14'),
(6, 1, 27, '2026-05-14 05:35:14'),
(7, 1, 2, '2026-05-14 05:35:14'),
(8, 1, 22, '2026-05-14 05:35:14'),
(9, 1, 17, '2026-05-14 05:35:14'),
(10, 1, 28, '2026-05-14 05:35:14'),
(11, 1, 1, '2026-05-14 05:35:14'),
(12, 1, 13, '2026-05-14 05:35:14'),
(13, 1, 20, '2026-05-14 05:35:14'),
(14, 1, 19, '2026-05-14 05:35:14'),
(15, 1, 29, '2026-05-14 05:35:14');

-- --------------------------------------------------------

--
-- Table structure for table `therapist_specialty_services`
--

CREATE TABLE `therapist_specialty_services` (
  `id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `admin_role` enum('owner','cashier') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `cashier_pin` char(4) DEFAULT NULL COMMENT '4-digit PIN for cashier action accountability'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `full_name`, `phone`, `address`, `role`, `admin_role`, `created_at`, `deleted_at`, `cashier_pin`) VALUES
(1, 'walkin_customer', 'N/A', 'walkin@spa.com', 'Walk-in Customer', 'N/A', 'Walk-in Customer', 'user', NULL, '2026-03-17 16:29:08', NULL, NULL),
(2, 'adminuser', '$2y$10$WCZgq3V29DnGkJVfrYUJtuhCbRBRavxmyZh.FdS3O37rW5dUALOl.', 'admin@user.com', 'admin Wellness', '123', 'none', 'admin', 'owner', '2026-03-15 08:43:40', NULL, NULL),
(6, 'admin', '$2y$10$iY1cPcry5Zsq4pTiEBvgfuUHUh1HDFmIEO7TZ5J/9tiplvU2Ct1UO', 'admin@spa.com', 'System Administrator', '09000000000', 'Admin Office', 'admin', 'owner', '2026-03-22 08:00:05', NULL, NULL),
(19, 'keanne123', '$2y$10$zlucqLPBM/tVUlRsNtEt1.eUO/LVpNYjGHH7CmcSqeJDruqNvXGO2', 'kent@gmail.com', 'kent clarence gonzalez', '09483941263', '', 'therapist', NULL, '2026-03-25 20:53:29', NULL, NULL),
(21, 'kikaydhez', '$2y$10$Gl69zbLaWg7L6JwRrg5OveFhQxG3xEyC3gkkQTl1GmLfh.gbaUWFe', 'fredilynalimato8@gmail.com', 'Fredilyn A. Gonzalez', '09459649213', 'Mambawi, Alimodian, Iloilo', 'user', NULL, '2026-03-26 10:58:14', NULL, NULL),
(26, 'keanne', '$2y$10$31zNhg1GZkICWJaQo/XZw.UAY52jvjxSHOi/hFWv6xP8wPIbKuP6q', 'Keer.gonzalez.ui@phinmaed.com', 'Kent Clarence Gonzalez', '09999999999', '123', 'user', NULL, '2026-05-18 10:36:46', NULL, NULL),
(27, 'Gabriel', '$2y$10$lpIwga24mv.Ihl5EnNLjK.WTz8NlCh7RtigFlhGdi0hmWtI/a2ira', 'cjob.candaganan.ui@phinmaed.com', 'Gabriel Candaganan', '09123456789', 'Iloilo City', 'user', NULL, '2026-05-18 10:51:06', NULL, NULL),
(28, 'hashi', '$2y$10$3JNSljXP8gnJvuRkMcoCi.o74HX5WMwdCTrTsO9z7dKNXC8y7Bmku', 'maba.guzman.ui@phinmaed.com', 'MA. FENNY BALINAS GUZMAN', '09271352557', 'San Miguel', 'user', NULL, '2026-05-18 10:51:07', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `order_item_id` (`order_item_id`),
  ADD KEY `idx_appt_therapist_date` (`therapist_id`,`appointment_date`);

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
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_fb_appointment` (`appointment_id`),
  ADD UNIQUE KEY `ux_fb_order` (`order_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `service_id` (`service_id`);

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
-- Indexes for table `receptionists`
--
ALTER TABLE `receptionists`
  ADD PRIMARY KEY (`id`);

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
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `appointment_extra_services`
--
ALTER TABLE `appointment_extra_services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appointment_therapists`
--
ALTER TABLE `appointment_therapists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `business_expenses`
--
ALTER TABLE `business_expenses`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `partners`
--
ALTER TABLE `partners`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `partner_rates`
--
ALTER TABLE `partner_rates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `receptionists`
--
ALTER TABLE `receptionists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `refund_requests`
--
ALTER TABLE `refund_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `therapists`
--
ALTER TABLE `therapists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `therapist_attendance`
--
ALTER TABLE `therapist_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `therapist_commission`
--
ALTER TABLE `therapist_commission`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `therapist_deductions`
--
ALTER TABLE `therapist_deductions`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `therapist_ratings`
--
ALTER TABLE `therapist_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `therapist_specialties`
--
ALTER TABLE `therapist_specialties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `therapist_specialty_services`
--
ALTER TABLE `therapist_specialty_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

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
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feedback_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `feedback_ibfk_3` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL;

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
