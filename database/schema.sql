-- Luqya AI database schema and seed data
-- ================================================================
-- Passwords and security codes are stored only as bcrypt hashes,
-- never in plaintext.
--
-- This file is the authoritative definition of the schema. The runtime
-- migrations in config/db.php (ensureSchemaUpgrades) bring older
-- databases up to exactly what is declared here.
--
-- Column widths below are mirrored by the constants in config/schema.php.
-- The server-side validators MUST NOT be wider than these columns: with
-- sql_mode lacking STRICT_TRANS_TABLES, MariaDB silently truncates
-- over-length values instead of raising an error, which corrupts data
-- (and degrades matching, which indexes on these very fields).
-- ================================================================

CREATE DATABASE IF NOT EXISTS `luqya_ai` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `luqya_ai`;

-- 1. Users
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `university_id` VARCHAR(20) NOT NULL UNIQUE,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `phone` VARCHAR(20) DEFAULT NULL,
  `college` VARCHAR(100) DEFAULT NULL,
  `major` VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('student', 'security', 'admin') DEFAULT 'student',
  `security_code` VARCHAR(20) DEFAULT NULL UNIQUE,
  `security_code_hash` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Reports (lost & found)
--    title/location/ai_tags were widened (150/150/255 -> 200/250/500) and
--    distinctive_features became TEXT, so the values produced by the vision
--    model are stored intact instead of being truncated mid-word.
CREATE TABLE IF NOT EXISTS `reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `type` ENUM('lost', 'found') NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `description` TEXT NOT NULL,
  `location` VARCHAR(250) NOT NULL,
  `date_event` DATE NOT NULL,
  `image_url` VARCHAR(255) DEFAULT NULL,
  `ai_tags` VARCHAR(500) DEFAULT NULL,
  `ocr_data` TEXT DEFAULT NULL,
  `distinctive_features` TEXT DEFAULT NULL,
  `status` ENUM('active', 'matched', 'pending_pickup', 'resolved', 'cancelled') DEFAULT 'active',
  `pickup_code` VARCHAR(20) DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_reports_user_status` (`user_id`, `status`),
  INDEX `idx_reports_status_type` (`status`, `type`),
  INDEX `idx_reports_pickup` (`pickup_code`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Matches
--    A pair of (lost, found) reports. The pair is unique, and a non-null
--    pickup_code is unique across the table, so a user can never be shown a
--    code that resolves to a different match. Rejected rows carry
--    pickup_code = NULL (MySQL allows many NULLs under a UNIQUE index).
--    status is 'pending' until an officer confirms or hands the item over.
CREATE TABLE IF NOT EXISTS `matches` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lost_report_id` INT NOT NULL,
  `found_report_id` INT NOT NULL,
  `confidence_score` INT NOT NULL DEFAULT 85,
  `pickup_code` VARCHAR(20) DEFAULT NULL,
  `status` ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
  `matched_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`lost_report_id`) REFERENCES `reports`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`found_report_id`) REFERENCES `reports`(`id`) ON DELETE CASCADE,
  UNIQUE INDEX `uk_matches_pair` (`lost_report_id`, `found_report_id`),
  UNIQUE INDEX `uk_matches_pickup` (`pickup_code`),
  CONSTRAINT `chk_matches_confidence` CHECK (`confidence_score` BETWEEN 0 AND 100)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) DEFAULT 'info',
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_notifications_feed` (`user_id`, `is_read`, `created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Activity logs
--    Append-only audit trail. details is TEXT because the details string is
--    a concatenation of the pickup code and the officer's full name, which
--    overflowed VARCHAR(255). No FK by design: the log must outlive the user.
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `action_type` VARCHAR(50) NOT NULL,
  `item_title` VARCHAR(200) NOT NULL,
  `details` TEXT NOT NULL,
  `user_name` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_activity_logs_created` (`created_at`),
  INDEX `idx_activity_logs_action` (`action_type`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Password resets
-- Stores only the SHA-256 hash of the temporary reset token (never the raw token),
-- with an expiry time and single-use consumption marker.
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_resets_user` (`user_id`),
  INDEX `idx_resets_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- Seed data
-- ================================================================
-- Credentials are bcrypt hashes only (see note at the top).

TRUNCATE TABLE `password_resets`;
TRUNCATE TABLE `matches`;
TRUNCATE TABLE `notifications`;
TRUNCATE TABLE `activity_logs`;
DELETE FROM `reports`;
DELETE FROM `users`;

INSERT INTO `users` (`id`, `university_id`, `full_name`, `email`, `phone`, `college`, `major`, `password_hash`, `role`, `security_code`, `security_code_hash`, `created_at`) VALUES
(1, 'SEC-USR-1', 'عائشة الشمري-الأمن الجامعي', 'sec-b1@uoh.edu.sa', '+966500000001', NULL, NULL, '$2y$10$GoQQ8FCVZXGqBMVei0KCv.LtVWDxgNsaVa.HZifiHZsIhJbC64tx2', 'security', NULL, '$2y$10$PuSbCHwjRtilRRC4W82OFez2UCdFHaB3.PenN.lAvZRg4v4AZvR/a', '2026-08-31 07:11:18'),
(2, 'S202403400', 'مرام الحربي', 's202403400@uoh.edu.sa', '+966551234567', NULL, NULL, '$2y$10$GyY/zRt53G.oq26f7HVQNOmcZJ/nKfOpFeLLnL0WxqdQDjg0Tp/qW', 'student', NULL, NULL, '2026-08-31 07:11:18'),
(3, 'S202409876', 'سارة العتيبي', 's202409876@uoh.edu.sa', '+966559876543', NULL, NULL, '$2y$10$GyY/zRt53G.oq26f7HVQNOmcZJ/nKfOpFeLLnL0WxqdQDjg0Tp/qW', 'student', NULL, NULL, '2026-08-31 07:11:18'),
(4, 'S202401234', 'فهد الشمري', 's202401234@uoh.edu.sa', '+966554321987', NULL, NULL, '$2y$10$GyY/zRt53G.oq26f7HVQNOmcZJ/nKfOpFeLLnL0WxqdQDjg0Tp/qW', 'student', NULL, NULL, '2026-08-31 07:11:18'),
(6, 'S202447940', 'منار عبدالله الشمري', 's202447940@uoh.edu.sa', '0533928899', NULL, NULL, '$2y$10$t8nrZ25GcGYhbOS8DclrdOFXaPXF1lPphyb4zmDeDRkZ33WO//ezm', 'student', NULL, NULL, '2026-09-03 12:11:48'),
(7, 'SEC-USR-7', 'فاطمة الحربي - الأمن الجامعي', 'sec-b2@uoh.edu.sa', '+966500000000', NULL, NULL, '$2y$10$ffRO1KK292LiaJu9U597jutt1z9fDSTwYN771e0eLduBliuyTycbe', 'security', NULL, '$2y$10$h.z9ZjiDeQThHkvEQMzVZ./4Lhydu2LN3F4yL6UjX6tAd.f64nBvK', '2026-09-16 23:30:16'),
(8, 'SEC-USR-8', 'محمد العنزي - ضابط الأمن', 'sec-g1@uoh.edu.sa', '+966500000000', NULL, NULL, '$2y$10$ffRO1KK292LiaJu9U597jutt1z9fDSTwYN771e0eLduBliuyTycbe', 'security', NULL, '$2y$10$Km3VSpFXR.tl2EuPOqLcIuHH4UUmJ5ngDC/qJ1ghO3wFUDA2RKXNu', '2026-09-16 23:30:16'),
(9, 'SEC-USR-9', 'سلطان الشمري - ضابط الأمن', 'sec-g2@uoh.edu.sa', '+966500000000', NULL, NULL, '$2y$10$ffRO1KK292LiaJu9U597jutt1z9fDSTwYN771e0eLduBliuyTycbe', 'security', NULL, '$2y$10$Rcyk/lVXM.q.SYo3bcS9k.0138HFbIlDq4XIAZAEvpXFMRhv/pUpi', '2026-09-16 23:30:16');

-- Reports
INSERT INTO `reports` (`id`, `user_id`, `type`, `title`, `category`, `description`, `location`, `date_event`, `image_url`, `ai_tags`, `ocr_data`, `distinctive_features`, `status`, `pickup_code`, `resolved_at`, `created_at`) VALUES
(1, 2, 'lost', 'محفظة جلدية سوداء', 'مقتنيات شخصية', 'فقدت محفظة جلدية سوداء ماركة مونت بلانك تحتوي على الهوية الوطنية وبطاقات بنكية بالقرب من بهو كلية الهندسة.', 'كلية الهندسة', '2026-08-14', 'assets/images/sample_wallet.svg', 'جلد، محفظة، أسود', NULL, NULL, 'resolved', 'RET-849201', NULL, '2026-08-31 07:11:18'),
(2, 3, 'found', 'محفظة جلدية سوداء (مونت بلانك)', 'مقتنيات شخصية', 'عثرت على محفظة جلدية سوداء على إحدى طاولات بهو كلية الهندسة وتم تسليمها لمكتب الأمن.', 'كلية الهندسة - البهو الرئيسي', '2026-08-14', 'assets/images/sample_wallet.svg', 'جلد، محفظة، أسود، مونت بلانك', NULL, NULL, 'resolved', 'RET-849201', NULL, '2026-08-31 07:11:18'),
(3, 2, 'lost', 'بطاقة جامعية للطالب مرام الحربي', 'البطاقة الجامعية / الصراف', 'فقدت بطاقتي الجامعية برقم S202403400 أثناء المحاضرة الصباحية في المكتبة.', 'المكتبة المركزية - الدور الثاني', '2026-08-15', 'assets/images/sample_id_card.svg', 'بطاقة جامعية، S202403400، علوم الحاسب', '{"student_id":"S202403400","name":"Ahmed Al-Harbi","college":"Computer Science & Engineering"}', NULL, 'matched', 'RET-912384', NULL, '2026-08-31 07:11:18'),
(5, 3, 'lost', 'سماعات أبل إيربودز برو الجيل الثاني', 'إلكترونيات', 'علبة سماعات إيربودز بيضاء مع خدش بسيط في الجهة الأمامية فقدتها في الكافتيريا.', 'كافتيريا مجمع الطلاب', '2026-08-16', 'assets/images/sample_airpods.svg', 'سماعات، لاسلكية، بيضاء، أبل', NULL, NULL, 'active', NULL, NULL, '2026-08-31 07:11:18'),
(6, 4, 'found', 'حقيبة ظهر نايكي زرقاء بداخلها دفاتر', 'حقائب وحقائب ظهر', 'حقيبة ظهر نايكي زرقاء تحتوي على مذكرات ومستلزمات دراسية لكلية علوم الحاسب.', 'القاعة الكبرى - مدرج ب', '2026-08-16', 'assets/images/sample_bag.svg', 'حقيبة، زرقاء، نايكي، دفاتر', NULL, NULL, 'active', NULL, NULL, '2026-08-31 07:11:18'),
(7, 2, 'lost', 'جهاز لابتوب HP Spectre x360', 'إلكترونيات', 'لابتوب HP فضي مقاس 13 بوصة داخل حافظة رمادية نسيته في المعمل.', 'معمل العلوم 304', '2026-08-10', 'assets/images/sample_laptop.svg', 'لابتوب، HP، فضي، إلكترونيات', NULL, NULL, 'resolved', 'RET-104928', NULL, '2026-08-31 07:11:18'),
(8, 1, 'found', 'كمبيوتر محمول HP فضي بحافظة رمادية', 'إلكترونيات', 'تم العثور على جهاز لابتوب فضي وتسليمه إلى مكتب الأمن مباشرة.', 'معمل العلوم 304', '2026-08-10', 'assets/images/sample_laptop.svg', 'لابتوب، HP، فضي، إلكترونيات', NULL, NULL, 'resolved', 'RET-104928', NULL, '2026-08-31 07:11:18'),
(10, 2, 'lost', 'شاحن هاتف مع كابل باللون أبيض', 'إلكترونيات', 'العنصر الظاهر: شاحن هاتف مع كابل. الألوان الظاهرة: أبيض، شفاف، أحمر، فضي. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: غطاء واقٍ شفاف على رأس الشاحن مزين بنقوش كرز وأزهار صغيرة، سلك شاحن مغلف بحامي حلزوني لامع وملفوف بشكل دائري، رباط سيليكون لتثبيت السلك، قابس كهربائي ثنائي الدبابيس.', 'كلية الهندسة', '2026-09-13', 'uploads/1789317864_photo_5848143374681050802_y.jpg', 'شاحن هاتف مع كابل، أبيض، شفاف، أحمر، فضي', '', NULL, 'active', NULL, NULL, '2026-09-13 19:44:24'),
(11, 2, 'found', 'كوب بمصاصة باللون أصفر فاتح', 'مقتنيات شخصية', 'العنصر الظاهر: كوب بمصاصة. الألوان الظاهرة: أصفر فاتح، أسود. العلامة المقروءة: puff. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: مصاصة علوية بارزة بلون أصفر فاتح، كلمة puff مطبوعة باللون الأسود في الجزء السفلي، خط أو حلقة رفيعة باللون الأسود عند الغطاء.', '14E', '2026-09-13', 'uploads/1789317965_photo_5848143374681050803_y.jpg', 'كوب بمصاصة، أصفر فاتح، أسود، puff، مصاصة علوية بارزة بلون أصفر فاتح', '', NULL, 'pending_pickup', 'RET-316587', NULL, '2026-09-13 19:46:05'),
(19, 2, 'lost', 'كوب مع شفاط باللون أصفر فاتح', 'مقتنيات شخصية', 'العنصر الظاهر: كوب مع شفاط. الألوان الظاهرة: أصفر فاتح، أسود. العلامة المقروءة: puff. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: يحتوي على شفاط مدمج، كلمة puff مطبوعة في الجزء السفلي، خط أسود نحيف يحيط بالغطاء، تصميم مطفأ اللمعة.', '14E', '2026-09-17', 'uploads/1789596604_3b98233e.jpg', 'مقتنيات شخصية', '', NULL, 'pending_pickup', 'RET-316587', NULL, '2026-09-17 01:10:04'),
(20, 2, 'found', 'هاتف محمول وسماعة أذن سلكية باللون أسود', 'إلكترونيات', 'العنصر الظاهر: هاتف محمول وسماعة أذن سلكية. الألوان الظاهرة: أسود، أبيض. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: سماعة أذن سلكية بيضاء متصلة بأسفل الهاتف، شاشة الهاتف مضاءة وتعرض واجهة تشغيل موسيقى.', 'كلية العلوم مبنى 15', '2026-09-17', 'uploads/1789598345_fe1e7357.jpeg', 'إلكترونيات، سماعات لاسلكية، هاتف ذكي', '{"student_id":"","name":""}', NULL, 'active', 'RET-818728', NULL, '2026-09-17 01:39:05'),
(24, 1, 'found', 'بطاقة جامعية باللون أبيض', 'البطاقة الجامعية / الصراف', 'العنصر الظاهر: بطاقة جامعية. الألوان الظاهرة: أبيض، أزرق، ذهبي. العلامة المقروءة: جامعة حائل. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: صورة شخصية لطالبة، رمز استجابة سريعة QR كود، باركود مطبوع أسفل البطاقة، شعار جامعة حائل، ملصق هولوغرام أمني في الزاوية.', 'مكتب الأمن الرئيسي', '2026-09-17', 'uploads/sec_1789599745_71d935d8.jpeg', 'البطاقة الجامعية / الصراف، بطاقة جامعية، إثبات هوية', '{"student_id":"S202402200","name":"سارة مرام الحربي"}', NULL, 'active', 'RET-566869', NULL, '2026-09-17 02:02:25'),
(25, 1, 'found', 'سماعة رأس باللون أزرق داكن', 'إلكترونيات', 'العنصر الظاهر: سماعة رأس. الألوان الظاهرة: أزرق داكن، رمادي، فضي. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: وسائد أذن بيضاوية رمادية، بطانة رأس شبكية، أزرار تحكم جانبية بارزة، مفاصل معدنية اللون، تصميم لاسلكي.', 'مكتب الأمن الرئيسي', '2026-09-17', 'uploads/sec_1789600580_e676ceb7.jpeg', 'إلكترونيات، سماعات لاسلكية', NULL, NULL, 'pending_pickup', 'RET-372629', NULL, '2026-09-17 02:16:20'),
(26, 2, 'lost', 'سماعة رأس باللون أزرق', 'إلكترونيات', 'العنصر الظاهر: سماعة رأس. الألوان الظاهرة: أزرق، فضي. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: طوق رأس شبكي، وسائد أذن بيضاوية مغطاة بنسيج شبكي، أذرع تمديد فضية، زر تحكم دائري أعلى غطاء الأذن، حافظة بلون أزرق متطابق.', 'كلية الاداب', '2026-09-17', 'uploads/1789600728_ea3c9a35.jpeg', 'إلكترونيات، سماعات لاسلكية', '', NULL, 'pending_pickup', 'RET-372629', NULL, '2026-09-17 02:18:48'),
(27, 2, 'found', 'مشغل موسيقى باللون فضي', 'إلكترونيات', 'العنصر الظاهر: مشغل موسيقى. الألوان الظاهرة: فضي، أبيض. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: عجلة تحكم دائرية بيضاء، شاشة ملونة، سماعات أذن سلكية بيضاء متصلة.', 'كلية علوم الحاسب', '2026-09-17', 'uploads/1789662107_fe0b6bde.jpeg', 'إلكترونيات، سماعات لاسلكية', '', NULL, 'resolved', 'RET-008137', '2026-09-18 01:40:30', '2026-09-17 19:21:47'),
(28, 2, 'lost', 'مشغل وسائط رقمي باللون فضي', 'إلكترونيات', 'العنصر الظاهر: مشغل وسائط رقمي. الألوان الظاهرة: فضي، أبيض. الحالة الظاهرة: لا توجد أضرار ظاهرة في الجزء المرئي. سمات مميزة: عجلة تحكم دائرية بيضاء مع زر منتصف فضي، شاشة عرض مستطيلة، سماعات أذن سلكية بيضاء متصلة بالجهاز.', 'كلية علوم الحاسب', '2026-09-17', 'uploads/1789662301_ecaa6269.jpeg', 'إلكترونيات، سماعات لاسلكية', '', NULL, 'resolved', 'RET-008137', '2026-09-17 19:25:21', '2026-09-17 19:25:01');

-- Matches
INSERT INTO `matches` (`id`, `lost_report_id`, `found_report_id`, `confidence_score`, `pickup_code`, `status`, `matched_at`, `resolved_at`) VALUES
(1, 1, 2, 96, 'RET-849201', 'verified', '2026-08-14 14:30:00', '2026-09-04 23:50:08'),
(3, 7, 8, 98, 'RET-104928', 'verified', '2026-08-10 16:00:00', NULL),
(6, 19, 11, 94, 'RET-316587', 'pending', '2026-09-17 01:10:04', NULL),
(8, 26, 25, 99, 'RET-372629', 'pending', '2026-09-17 02:18:48', NULL),
(9, 28, 27, 99, 'RET-008137', 'verified', '2026-09-17 19:25:01', '2026-09-18 01:40:30');

-- Notifications
INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(1, 2, ' تم العثور على مطابقة ذكية بنسبة عالية!', 'تمت مطابقة غرضك المفقود "محفظة جلدية سوداء" بنسبة (96%) مع بلاغ معثورات. كود التحقق والاستلام الآمن الخاص بك هو: RET-849201. يرجى إبراز هذا الكود لمكتب الأمن لاستلام مفقوداتك.', 'match_found', 1, '2026-08-14 14:30:00'),
(2, 2, ' تنبيه مطابقة ماسح البطاقات الجامعية', 'تم التعرف على بطاقتك الجامعية المفقودة (S202403400) عبر الماسح الضوئي! كود التوثيق للاستلام: RET-912384.', 'match_found', 1, '2026-08-15 11:15:00'),
(3, 2, 'تم تسليم العنصر بنجاح', 'تم تسليم جهاز اللابتوب HP Spectre x360 بنجاح عبر مكتب الأمن وإغلاق البلاغ.', 'resolved', 1, '2026-08-10 16:30:00'),
(4, 2, 'تم تسليم العنصر بنجاح وإغلاق البلاغ', 'تم تسليم مفقوداتك "محفظة جلدية سوداء ماركة مونت بلانك" بنجاح عبر مكتب الأمن وإغلاق البلاغ.', 'resolved', 1, '2026-09-04 23:50:08'),
(5, 3, 'تم تسليم العنصر بنجاح وإغلاق البلاغ', 'تم تسليم مفقوداتك "محفظة جلدية سوداء (مونت بلانك)" بنجاح عبر مكتب الأمن وإغلاق البلاغ.', 'resolved', 1, '2026-09-04 23:50:08'),
(6, 1, 'تم تسليم العنصر بنجاح وإغلاق البلاغ', 'تم تسليم مفقوداتك "SMOKE-TEST-2141928453" بنجاح عبر مكتب الأمن وإغلاق البلاغ رسمياً.', 'resolved', 1, '2026-09-16 22:00:34'),
(9, 2, ' تم العثور على مطابقة ذكية بنسبة عالية!', 'وجدنا مطابقة ذكية باحتمال 94% بين غرضك المفقود "كوب مع شفاط باللون أصفر فاتح" والمعثور عليه "كوب بمصاصة باللون أصفر فاتح". كود التوثيق والاستلام الآمن: RET-316587. يرجى إبراز الكود لمكتب الأمن.', 'match_found', 1, '2026-09-17 01:10:04'),
(10, 2, ' تم العثور على مطابقة ذكية بنسبة عالية!', 'وجدنا مطابقة ذكية باحتمال 99% بين غرضك المفقود "سماعة رأس باللون أزرق" والمعثور عليه "سماعة رأس باللون أزرق داكن". كود التوثيق والاستلام الآمن: RET-372629. يرجى إبراز الكود لمكتب الأمن.', 'match_found', 1, '2026-09-17 02:18:48'),
(11, 2, ' تم العثور على مطابقة ذكية بنسبة عالية!', 'وجدنا مطابقة ذكية باحتمال 99% بين غرضك المفقود "مشغل وسائط رقمي باللون فضي" والمعثور عليه "مشغل موسيقى باللون فضي". كود التوثيق والاستلام الآمن: RET-008137. يرجى إبراز الكود لمكتب الأمن.', 'match_found', 1, '2026-09-17 19:25:01');

-- Activity logs
INSERT INTO `activity_logs` (`id`, `action_type`, `item_title`, `details`, `user_name`, `created_at`) VALUES
(1, 'handover', 'جهاز لابتوب HP Spectre x360', 'تم تأكيد تسليم الجهاز للطالب والتحقق من كود الاستلام RET-104928.', 'ضابط الأمن المنصوري', '2026-08-10 16:30:00'),
(2, 'match', 'محفظة جلدية سوداء ماركة مونت بلانك', 'طابق محرك الذكاء الاصطناعي بلاغات المفقودات والمعثورات بنسبة دقة 96%.', 'RetrievAI Engine', '2026-08-14 14:30:00'),
(3, 'ocr_scan', 'بطاقة جامعية برقم S202403400', 'قام الماسح الضوئي بمسح البطاقة واستخراج بيانات الطالب مرام الحربي.', 'RetrievAI OCR Module', '2026-08-15 11:15:00'),
(4, 'handover', 'ساعة يد كلاسيكية', 'تحقق المالك من كود الاستلام واستلم مفقوداته من مكتب الأمن.', 'ضابط الأمن المنصوري', '2026-08-13 09:45:00'),
(5, 'handover', 'محفظة جلدية سوداء ماركة مونت بلانك', 'تم تأكيد التسليم والتحقق من كود الاستلام RET-849201 بواسطة ضابط الأمن.', 'ضابط الأمن المنصوري', '2026-09-04 23:50:08'),
(6, 'add_found', 'SMOKE-TEST-d1d4', 'قام ضابط الأمن بتسجيل معثور عليه جديد في المخزون: SMOKE-TEST-d1d4', 'Test', '2026-09-16 21:59:49'),
(7, 'add_found', 'SMOKE-TEST-2141928453', 'قام ضابط الأمن بتسجيل معثور عليه جديد في المخزون: SMOKE-TEST-2141928453', 'Test', '2026-09-16 22:00:26'),
(8, 'handover', 'SMOKE-TEST-2141928453', 'تم تأكيد التسليم الفعلي والتحقق من كود الاستلام RET-972745 بواسطة Test', 'Test', '2026-09-16 22:00:34'),
(11, 'add_found', 'شاحن هاتف باللون الأبيض مع كابل', 'قام ضابط الأمن بتسجيل معثور عليه جديد في المخزون: شاحن هاتف باللون الأبيض مع كابل', 'موظف الأمن', '2026-09-16 23:12:47'),
(12, 'add_found', 'سماعات سامسونج لاسلكية بيضاء', 'قام ضابط الأمن بتسجيل معثور عليه جديد في المخزون: سماعات سامسونج لاسلكية بيضاء', 'موظف الأمن', '2026-09-16 23:14:04'),
(13, 'match', 'كوب بمصاصة باللون أصفر فاتح', 'طابق محرك المطابقة الموزون البلاغات بنسبة 94%. كود الاستلام: RET-316587', 'RetrievAI Matching Engine', '2026-09-17 01:10:04'),
(14, 'add_found', 'هاتف ذكي باللون أسود', 'قام ضابط الأمن بتسجيل معثور عليه جديد في المخزون: هاتف ذكي باللون أسود', 'ضابط الأمن المنصوري', '2026-09-17 01:41:28'),
(17, 'add_found', 'بطاقة جامعية باللون أبيض', 'قام ضابط الأمن بتسجيل معثور عليه جديد في المخزون: بطاقة جامعية باللون أبيض', 'ضابط الأمن المنصوري', '2026-09-17 02:02:25'),
(20, 'add_found', 'سماعة رأس باللون أزرق داكن', 'قام ضابط الأمن بتسجيل معثور عليه جديد في المخزون: سماعة رأس باللون أزرق داكن', 'ضابط الأمن المنصوري', '2026-09-17 02:16:20'),
(21, 'match', 'سماعة رأس باللون أزرق داكن', 'طابق محرك المطابقة الموزون البلاغات بنسبة 99%. كود الاستلام: RET-372629', 'RetrievAI Matching Engine', '2026-09-17 02:18:48'),
(22, 'match', 'مشغل موسيقى باللون فضي', 'طابق محرك المطابقة الموزون البلاغات بنسبة 99%. كود الاستلام: RET-008137', 'RetrievAI Matching Engine', '2026-09-17 19:25:01');