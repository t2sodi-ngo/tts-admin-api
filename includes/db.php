<?php
// includes/db.php

// Set timezone to Indian Standard Time (IST)
date_default_timezone_set('Asia/Kolkata');

$db_dir = __DIR__ . '/../database';
if (!file_exists($db_dir)) {
    @mkdir($db_dir, 0755, true);
}

$db_file = $db_dir . '/t2s.db';

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Enable foreign keys
    $pdo->exec("PRAGMA foreign_keys = ON;");
    
    // 1. Users table (Admin Panel access)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('super_admin', 'editor', 'viewer')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 2. Donations table
    $pdo->exec("CREATE TABLE IF NOT EXISTS donations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receipt_no TEXT NOT NULL UNIQUE,
        donor_name TEXT NOT NULL,
        donor_email TEXT NOT NULL,
        donor_phone TEXT NOT NULL,
        donor_pan TEXT DEFAULT NULL,
        donor_address TEXT DEFAULT NULL,
        amount REAL NOT NULL,
        payment_mode TEXT NOT NULL,
        transaction_id TEXT NOT NULL UNIQUE,
        purpose TEXT NOT NULL,
        frequency TEXT NOT NULL CHECK(frequency IN ('one-time', 'monthly')),
        status TEXT NOT NULL CHECK(status IN ('captured', 'pending', 'rejected')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // Migration: Check if 'rejected' is allowed in donations status check constraint
    try {
        $info = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='donations'")->fetchColumn();
        if ($info && strpos($info, "'rejected'") === false) {
            $pdo->exec("PRAGMA foreign_keys=OFF;");
            $pdo->exec("BEGIN TRANSACTION;");
            
            // Create temporary table with new constraint
            $pdo->exec("CREATE TABLE donations_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                receipt_no TEXT NOT NULL UNIQUE,
                donor_name TEXT NOT NULL,
                donor_email TEXT NOT NULL,
                donor_phone TEXT NOT NULL,
                donor_pan TEXT DEFAULT NULL,
                donor_address TEXT DEFAULT NULL,
                amount REAL NOT NULL,
                payment_mode TEXT NOT NULL,
                transaction_id TEXT NOT NULL UNIQUE,
                purpose TEXT NOT NULL,
                frequency TEXT NOT NULL CHECK(frequency IN ('one-time', 'monthly')),
                status TEXT NOT NULL CHECK(status IN ('captured', 'pending', 'rejected')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                screenshot_path TEXT DEFAULT NULL,
                donor_org TEXT DEFAULT NULL,
                donor_dob TEXT DEFAULT NULL,
                donor_city TEXT DEFAULT NULL,
                donor_state TEXT DEFAULT NULL,
                donor_pin TEXT DEFAULT NULL,
                donor_anonymous INTEGER DEFAULT 0
            );");
            
            // Copy data
            $pdo->exec("INSERT INTO donations_new (id, receipt_no, donor_name, donor_email, donor_phone, donor_pan, donor_address, amount, payment_mode, transaction_id, purpose, frequency, status, created_at) SELECT id, receipt_no, donor_name, donor_email, donor_phone, donor_pan, donor_address, amount, payment_mode, transaction_id, purpose, frequency, status, created_at FROM donations;");
            
            // Drop old table
            $pdo->exec("DROP TABLE donations;");
            
            // Rename new table
            $pdo->exec("ALTER TABLE donations_new RENAME TO donations;");
            
            $pdo->exec("COMMIT;");
            $pdo->exec("PRAGMA foreign_keys=ON;");
        }
    } catch (Exception $e) {
        // Silent catch
    }


    // 3. Volunteers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS volunteers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NOT NULL,
        city TEXT NOT NULL,
        area_of_interest TEXT NOT NULL,
        availability TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'Pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 20. District Boundaries table
    $pdo->exec("CREATE TABLE IF NOT EXISTS district_boundaries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        district_name TEXT NOT NULL UNIQUE,
        geojson_data TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 4. Events table
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        event_date DATETIME NOT NULL,
        venue TEXT NOT NULL,
        image_url TEXT NOT NULL,
        status TEXT NOT NULL CHECK(status IN ('upcoming', 'past')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 5. Event Registrations table
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('attendee', 'volunteer')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    );");

    // 6. Blog Posts table
    $pdo->exec("CREATE TABLE IF NOT EXISTS blog_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        image_url TEXT NOT NULL,
        category TEXT NOT NULL CHECK(category IN ('Press', 'Events', 'Programs', 'Stories')),
        author_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'published',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
    );");

    // 7. Gallery table
    $pdo->exec("CREATE TABLE IF NOT EXISTS gallery (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        image_url TEXT NOT NULL,
        category TEXT NOT NULL CHECK(category IN ('Youth', 'Women', 'Health', 'Env', 'Festivals', 'Events', 'Media')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    try {
        $pdo->exec("ALTER TABLE gallery ADD COLUMN subtitle TEXT DEFAULT NULL;");
    } catch (PDOException $e) {
        // Column already exists
    }

    // 8. Newsletter Subscribers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL CHECK(status IN ('active', 'unsubscribed')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 9. Contact Messages table
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NOT NULL,
        subject TEXT NOT NULL,
        message TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'unread',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 10. Settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL
    );");

    // 11. Hero Slides table
    $pdo->exec("CREATE TABLE IF NOT EXISTS hero_slides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        image_url TEXT NOT NULL,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 12. Certificates table
    $pdo->exec("CREATE TABLE IF NOT EXISTS certificates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        filename TEXT NOT NULL,
        file_path TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 13. Board Members table
    $pdo->exec("CREATE TABLE IF NOT EXISTS board_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        role TEXT NOT NULL,
        message TEXT DEFAULT NULL,
        image_url TEXT NOT NULL,
        sort_order INTEGER DEFAULT 0,
        is_founder INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 14. Testimonials table
    $pdo->exec("CREATE TABLE IF NOT EXISTS testimonials (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        role TEXT NOT NULL,
        quote TEXT NOT NULL,
        image_url TEXT DEFAULT NULL,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 15. Partners table
    $pdo->exec("CREATE TABLE IF NOT EXISTS partners (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        webpage_link TEXT DEFAULT NULL,
        logo_url TEXT DEFAULT NULL,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 16. Newspaper Mentions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS newspaper_mentions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT DEFAULT NULL,
        image_url TEXT DEFAULT NULL,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 17. Gallery Pillars table
    $pdo->exec("CREATE TABLE IF NOT EXISTS gallery_pillars (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        subtitle TEXT DEFAULT NULL,
        image_url TEXT DEFAULT NULL,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 18. Gallery Videos table
    $pdo->exec("CREATE TABLE IF NOT EXISTS gallery_videos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        video_url TEXT NOT NULL,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 19. Impact Districts table
    $pdo->exec("CREATE TABLE IF NOT EXISTS impact_districts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        district_name TEXT NOT NULL UNIQUE,
        camps_run INTEGER DEFAULT 0,
        people_helped INTEGER DEFAULT 0,
        featured_program TEXT DEFAULT NULL,
        color_hex TEXT DEFAULT '#5046e5',
        status TEXT DEFAULT 'active' CHECK(status IN ('active', 'inactive')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 21. Login Attempts table (brute-force protection)
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_address TEXT NOT NULL,
        attempts INTEGER DEFAULT 1,
        last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 22. Activity Audit Log table
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_name TEXT NOT NULL,
        admin_role TEXT NOT NULL,
        action TEXT NOT NULL,
        details TEXT,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // Seed default admin if no users exist
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $adminPassword = password_hash('AdminPassword123!', PASSWORD_BCRYPT);
        $stmt->execute(['Super Admin', 'admin@timetoshine.co.in', $adminPassword, 'super_admin']);
        
        // Also seed an Editor and a Viewer for demonstration
        $editorPassword = password_hash('EditorPassword123!', PASSWORD_BCRYPT);
        $stmt->execute(['Content Editor', 'editor@timetoshine.co.in', $editorPassword, 'editor']);
        
        $viewerPassword = password_hash('ViewerPassword123!', PASSWORD_BCRYPT);
        $stmt->execute(['Trustee Viewer', 'viewer@timetoshine.co.in', $viewerPassword, 'viewer']);
    }

    // Seed initial settings
    $settingsCount = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($settingsCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $default_settings = [
            'site_name' => 'Time to Shine',
            'tagline' => 'A Journey from Darkness to Light',
            'email' => 'contact@timetoshine.co.in',
            'phone' => '7657059201',
            'address' => 'Bhubaneswar, Odisha, India',
            'whatsapp' => '7657059201',
            'reg_no' => 'TTS/2026/OD-98213',
            'tax_80g' => 'TTS-80G-APPROVED-2026',
            'facebook' => 'https://facebook.com/timetoshine',
            'instagram' => 'https://instagram.com/timetoshine',
            'twitter' => 'https://twitter.com/timetoshine'
        ];
        foreach ($default_settings as $key => $val) {
            $stmt->execute([$key, $val]);
        }
    }

    // Seed initial events if none exist
    $eventCount = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
    if ($eventCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO events (title, description, event_date, venue, image_url, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Utsav of Unity 2026',
            'Our flagship annual cultural showcase celebrating the talents of special children and under-privileged youths from all over Odisha. Expect dance, music, and an exhibition of handmade arts.',
            '2026-11-15 17:00:00',
            'Rabindra Mandap, Bhubaneswar',
            'https://images.unsplash.com/photo-1511795409834-ef04bbd61622?auto=format&fit=crop&w=800&q=80',
            'upcoming'
        ]);
        
        $stmt->execute([
            'SALAAM Event - Security Forces & Police Honor',
            'Honouring and felicitating the brave personnel of the Indian Army, BSF, State Police, Traffic Police, and Security Guards for their dedicated service to our nation.',
            '2026-05-12 10:00:00',
            'Jayadev Bhawan, Bhubaneswar',
            'https://images.unsplash.com/photo-1541872703-74c5e44368f9?auto=format&fit=crop&w=800&q=80',
            'past'
        ]);

        $stmt->execute([
            'Fashion Fusion Gala 2026',
            'Celebrating the beauty and creative talents of special children and differently-abled individuals in design and ramp-walk modeling.',
            '2026-02-14 18:00:00',
            'Mayfair Convention, Bhubaneswar',
            'https://images.unsplash.com/photo-1469571486090-c5ff29097385?auto=format&fit=crop&w=800&q=80',
            'past'
        ]);
    }

    // Seed initial districts if none exist
    $districtCount = $pdo->query("SELECT COUNT(*) FROM impact_districts")->fetchColumn();
    if ($districtCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO impact_districts (district_name, camps_run, people_helped, featured_program, color_hex) VALUES (?, ?, ?, ?, ?)");
        
        $stmt->execute([
            'Khordha', 12, 3500, 'Security Forces Honor & Nutrition', '#5046e5'
        ]);
        $stmt->execute([
            'Cuttack', 8, 2100, 'Women Skill Training', '#ff6a00'
        ]);
        $stmt->execute([
            'Puri', 5, 1200, 'Health & Hygiene Camps', '#10b981'
        ]);
        $stmt->execute([
            'Ganjam', 3, 850, 'Education Support', '#eab308'
        ]);
    }

    // Seed initial blog posts if none exist
    $blogCount = $pdo->query("SELECT COUNT(*) FROM blog_posts")->fetchColumn();
    if ($blogCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO blog_posts (title, content, image_url, category, author_id) VALUES (?, ?, ?, ?, ?)");
        
        $adminId = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
        
        $stmt->execute([
            'Honouring Our Real Heroes through SALAAM',
            'Our recent SALAAM honor ceremony in Bhubaneswar felicitated over 100 personnel from the Army, BSF, Odisha Police, and local security guards. Volunteers presented handmade Rakhis, saplings, and appreciation kits to honor those who stand guard for our safety every single day.',
            'https://images.unsplash.com/photo-1541872703-74c5e44368f9?auto=format&fit=crop&w=800&q=80',
            'Programs',
            $adminId
        ]);

        $stmt->execute([
            'Annual Blanket Drive Completed Successfully',
            'With the support of our corporate sponsors and generous individual donors, we distributed over 1,500 blankets to vulnerable families sleeping on street pavements and remote forest hamlets in winter night drives. Our team covered Cuttack, Puri, and Khurda districts. We thank every volunteer who stood up in freezing night hours to bring warmth to the needy.',
            'https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?auto=format&fit=crop&w=800&q=80',
            'Stories',
            $adminId
        ]);

        $stmt->execute([
            'Press Release: Dharitri Coverage of Fashion Fusion',
            'We are thrilled to share that Odisha\'s prominent daily Dharitri covered our Fashion Fusion event in full-page detail. The article appreciated our effort to build confidence among children with autism and down-syndrome. We thank the media houses for supporting inclusive society building and magnifying the voices of the silent.',
            'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=800&q=80',
            'Press',
            $adminId
        ]);
    }

    // Seed initial gallery if empty
    $galleryCount = $pdo->query("SELECT COUNT(*) FROM gallery")->fetchColumn();
    if ($galleryCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO gallery (title, image_url, category) VALUES (?, ?, ?)");
        
        $gallery_items = [
            ['SALAAM Career Counseling', 'https://images.unsplash.com/photo-1434030216411-0b793f4b4173?auto=format&fit=crop&w=800&q=80', 'Youth'],
            ['Blanket Distribution in Puri', 'https://images.unsplash.com/photo-1509099836639-18ba1795216d?auto=format&fit=crop&w=800&q=80', 'Env'],
            ['Women Skill Training Centre', 'https://images.unsplash.com/photo-1573164713988-8665fc963095?auto=format&fit=crop&w=800&q=80', 'Women'],
            ['Special Children Art Exhibition', 'https://images.unsplash.com/photo-1513364776144-60967b0f800f?auto=format&fit=crop&w=800&q=80', 'Festivals'],
            ['Health Check-up Drive', 'https://images.unsplash.com/photo-1505751172876-fa1923c5c528?auto=format&fit=crop&w=800&q=80', 'Health'],
            ['Tree Plantation in Bhubaneswar', 'https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?auto=format&fit=crop&w=800&q=80', 'Env']
        ];

        foreach ($gallery_items as $item) {
            $stmt->execute([$item[0], $item[1], $item[2]]);
        }
    }

    // Seed initial hero slides if empty
    $heroSlidesCount = $pdo->query("SELECT COUNT(*) FROM hero_slides")->fetchColumn();
    if ($heroSlidesCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO hero_slides (image_url, sort_order) VALUES (?, ?)");
        $stmt->execute(['https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?auto=format&fit=crop&w=1600&q=80', 1]);
        $stmt->execute(['https://images.unsplash.com/photo-1524178232363-1fb2b075b655?auto=format&fit=crop&w=1600&q=80', 2]);
        $stmt->execute(['https://images.unsplash.com/photo-1540575467063-178a50c2df87?auto=format&fit=crop&w=1600&q=80', 3]);
    }

    // Seed initial certificates if empty
    $certsCount = $pdo->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
    if ($certsCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO certificates (title, filename, file_path) VALUES (?, ?, ?)");
        $stmt->execute(['Trust Deed & Registration Certificate', 'trust_deed.pdf', '/assets/docs/trust_deed.pdf']);
        $stmt->execute(['12A Registration Approval', '12a_registration.pdf', '/assets/docs/12a_registration.pdf']);
        $stmt->execute(['80G Tax Exemption Certificate', '80g_certificate.pdf', '/assets/docs/80g_certificate.pdf']);
        $stmt->execute(['CSR-1 Registration Certificate', 'csr_1_certificate.pdf', '/assets/docs/csr_1_certificate.pdf']);
    }

    // Seed initial board members if empty
    $boardCount = $pdo->query("SELECT COUNT(*) FROM board_members")->fetchColumn();
    if ($boardCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO board_members (name, role, message, image_url, sort_order, is_founder) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Dr. Priyabrata Mohanty',
            'Founder & President',
            'Time to Shine Social Charity Trust was established to uplift the most marginalized members of our community in Odisha. Our journey from darkness to light is fueled by the dedication of our young volunteers and the trust of our generous donors. Together, we can build a society where every child gets a chance to shine.',
            'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?auto=format&fit=crop&w=500&q=80',
            1,
            1
        ]);
        $stmt->execute([
            'Mrs. Tanmayee Rath',
            'Managing Trustee',
            'Managing daily welfare campaigns and coordination of women vocational development centers.',
            'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=500&q=80',
            2,
            0
        ]);
        $stmt->execute([
            'Mr. Soumya Ranjan Das',
            'Treasurer & Trustee',
            'Overseeing audit compliance, volunteer registrations, and financial governance.',
            'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?auto=format&fit=crop&w=500&q=80',
            3,
            0
        ]);
    }

    // Seed initial testimonials if empty
    $testimonialsCount = $pdo->query("SELECT COUNT(*) FROM testimonials")->fetchColumn();
    if ($testimonialsCount == 0) {
        $stmt = $pdo->prepare("INSERT INTO testimonials (name, role, quote, image_url, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            'Dr. Alok Mohanty',
            'Former Prof, Utkal University',
            'Watching the kids shine on stage during Utsav of Unity was a highly emotional experience. Time to Shine NGO does incredible work building real integration and confidence for differently abled children.',
            'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=150&q=80',
            1
        ]);
        $stmt->execute([
            'Priya Darshini',
            'Corporate Volunteer, Bhubaneswar',
            'As a volunteer, I have participated in three medical camps and tree plantation drives. The transparency, detailed organization planning, and impact verification of Time to Shine are exemplary.',
            'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=150&q=80',
            2
        ]);
        $stmt->execute([
            'Sujit Kumar Das',
            'IT Consultant, Cuttack',
            'Supporting their monthly grocery drive was easy and straightforward. I received an automated 80G tax receipt on email immediately. Truly digital and professional charity trust in Odisha.',
            'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=150&q=80',
            3
        ]);
    }

    // Initialize UPI address in settings if not present
    $stmt_upi = $pdo->prepare("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('upi_address', 'timetoshine@indianbk')");
    $stmt_upi->execute();

    // Migration: Add screenshot_path to donations table if not exists
    try {
        $pdo->exec("ALTER TABLE donations ADD COLUMN screenshot_path TEXT DEFAULT NULL");
    } catch (PDOException $ex) {
        // Column already exists, safe to ignore
    }

    // Migration: Add extended donor profile fields
    $extended_cols = [
        "ALTER TABLE donations ADD COLUMN donor_org TEXT DEFAULT NULL",
        "ALTER TABLE donations ADD COLUMN donor_dob TEXT DEFAULT NULL",
        "ALTER TABLE donations ADD COLUMN donor_city TEXT DEFAULT NULL",
        "ALTER TABLE donations ADD COLUMN donor_state TEXT DEFAULT NULL",
        "ALTER TABLE donations ADD COLUMN donor_pin TEXT DEFAULT NULL",
        "ALTER TABLE donations ADD COLUMN donor_anonymous INTEGER DEFAULT 0",
    ];
    foreach ($extended_cols as $col_sql) {
        try {
            $pdo->exec($col_sql);
        } catch (PDOException $ex) {
            // Column already exists, safe to ignore
        }
    }

    // Migration: Add TOTP 2FA fields to users table
    $totp_cols = [
        "ALTER TABLE users ADD COLUMN totp_secret TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN totp_enabled INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN preferred_2fa TEXT DEFAULT 'email'",
    ];
    foreach ($totp_cols as $col_sql) {
        try {
            $pdo->exec($col_sql);
        } catch (PDOException $ex) {
            // Column already exists, safe to ignore
        }
    }

    // Migration: Visitor counter table
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT NOT NULL UNIQUE,
        ip_hash TEXT NOT NULL,
        visited_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // Migration: API tokens table for mobile app authentication
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        device_name TEXT DEFAULT 'Android App',
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );");

    // 21. Page SEO table
    $pdo->exec("CREATE TABLE IF NOT EXISTS page_seo (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_name TEXT NOT NULL UNIQUE,
        meta_title TEXT NOT NULL,
        meta_description TEXT NOT NULL,
        meta_keywords TEXT NOT NULL,
        og_image TEXT DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // Seed default page SEO metadata if empty
    $seoCount = $pdo->query("SELECT COUNT(*) FROM page_seo")->fetchColumn();
    if ($seoCount == 0) {
        $default_seos = [
            [
                'index.php',
                'Time to Shine',
                'Time to Shine Social Charity Trust is a registered NGO in Odisha empowering youth, women, and special children through actionable welfare programs.',
                'NGO in Bhubaneswar, Time to Shine Trust, charity in Odisha, youth empowerment, special children support, women welfare',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ],
            [
                'about.php',
                'About Us',
                'Learn about our journey, vision, mission, and the dedicated board members of Time to Shine Social Charity Trust in Odisha.',
                'About Time to Shine, NGO Board Members, Odisha Charity Vision, Founder Priyabrata Mohanty',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ],
            [
                'programs.php',
                'Our Programs & Reach',
                'Explore our core programs including Youth SALAAM, Women Empowerment, Differently Abled Support, Medical Camps, and Environment Protection drives across Odisha.',
                'NGO Programs Odisha, Youth SALAAM, Women Empowerment, Medical Camps Bhubaneswar, environment protection',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ],
            [
                'gallery.php',
                'Media Gallery',
                'Browse through raw snapshots, YouTube video journals, and newspaper clipping highlights of our on-ground social work in Odisha.',
                'NGO Gallery, Time to Shine Media, Odisha Charity photos, newspaper press clippings',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ],
            [
                'events.php',
                'Events & Campaigns',
                'Register for our upcoming social campaigns, view details of events, and join us on-ground as a volunteer in Odisha.',
                'Charity events Bhubaneswar, volunteer registration, upcoming NGO campaigns, Odisha Seva',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ],
            [
                'contact.php',
                'Contact Us',
                'Get in touch with Time to Shine Trust. Reach out for partnership, support, or general inquiries in Bhubaneswar, Odisha.',
                'Contact Time to Shine, NGO address Bhubaneswar, email timetoshine, phone number',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ],
            [
                'get-involved.php',
                'Volunteer & Get Involved',
                'Join our active volunteer network, apply for corporate partnerships, or sponsor special day food drives in Bhubaneswar and Cuttack.',
                'Volunteer Bhubaneswar, sponsor birthday food, corporate CSR Odisha, NGO volunteer join',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ],
            [
                'donate.php',
                'Donate & Support',
                'Sponsor meals, educational kits, or village medical drives. Your donations are eligible for 80G tax exemption in India.',
                'Donate to NGO Odisha, 80G tax exemption charity, sponsor child education, online donation UPI',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ],
            [
                'seva-quiz.php',
                'Find Your Seva Quiz',
                'Take our interactive quiz to find your ideal volunteering or donation path with Time to Shine Trust in Odisha.',
                'Seva quiz, volunteer matching, interactive NGO quiz, find my charity path',
                'https://timetoshine.co.in/assets/images/logo/logo.png'
            ]
        ];

        $stmt = $pdo->prepare("INSERT INTO page_seo (page_name, meta_title, meta_description, meta_keywords, og_image) VALUES (?, ?, ?, ?, ?)");
        foreach ($default_seos as $seo_item) {
            $stmt->execute($seo_item);
        }
    }

    // --- Volunteer Login & Hours Tracker Schema Extension ---
    try {
        $pdo->exec("ALTER TABLE volunteers ADD COLUMN password TEXT DEFAULT NULL;");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_volunteers_email ON volunteers(email);");
    } catch (PDOException $e) {
        // Index already exists, ignore
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS volunteer_hours (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        volunteer_id INTEGER NOT NULL,
        event_id INTEGER DEFAULT NULL,
        activity_date DATE NOT NULL,
        hours REAL NOT NULL,
        description TEXT NOT NULL,
        status TEXT NOT NULL CHECK(status IN ('pending', 'approved', 'rejected')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (volunteer_id) REFERENCES volunteers(id) ON DELETE CASCADE,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
    );");

} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
