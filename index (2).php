<?php
$domain = $_SERVER['HTTP_HOST'];
include 'config.php';
require_once 'auto_update_db.php';
require_once 'auto_sync_data.php';

// 如果开头是 www.，自动去掉再重定向
if (strpos($domain, 'www.') === 0) {
  $redirectDomain = substr($domain, 4); // 去掉 "www."
  $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
  $url .= "://" . $redirectDomain . $_SERVER['REQUEST_URI'];
  header("Location: $url", true, 301);
  exit;
}

// 假设用户语言ID是通过URL参数传的，例如 ?lang=1
$language_id = isset($_GET['lang']) ? intval($_GET['lang']) : 1;

$stmt = $pdo->prepare("SELECT company_name FROM domain_list WHERE domain_name = ?");
$stmt->execute([$domain]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  echo "<h2>Website not configured for this domain: $domain</h2>";
  exit;
}

$company_name = $row['company_name'];
$prefix = $company_name . "_";

// 自动同步数据库 schema
try {
    autoSyncCompanySchema($pdo, $prefix);
} catch (Exception $e) {
    echo "Error during schema sync: " . $e->getMessage();
    exit;
}

// 根据 language_id 获取公司信息
$stmt = $pdo->prepare("SELECT * FROM {$prefix}companyInfo WHERE domain = ? AND language_id = ?");
$stmt->execute([$domain, $language_id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
  echo "<h2>Company info not found for language ID $language_id in {$prefix}companyInfo</h2>";
  exit;
}


// Banner 不需要多语言
$bannerStmt = $pdo->prepare("SELECT image FROM {$prefix}companyBanner");
$bannerStmt->execute();
$company['banners'] = $bannerStmt->fetchAll(PDO::FETCH_COLUMN);

// Features 加 language_id
$features = $pdo->prepare("SELECT * FROM {$prefix}companyFeatures WHERE company_id = ? AND language_id = ?");
$features->execute([$company['id'], $language_id]);
$company['features'] = $features->fetchAll(PDO::FETCH_ASSOC);

// Provides 加 language_id
$provides = $pdo->prepare("SELECT * FROM {$prefix}companyProvides WHERE company_id = ? AND language_id = ?");
$provides->execute([$company['id'], $language_id]);
$company['provide'] = $provides->fetchAll(PDO::FETCH_ASSOC);

// Gallery 不需要多语言
$gallery = $pdo->prepare("SELECT image_path, caption FROM {$prefix}companyGallery");
$gallery->execute();
$company['gallery'] = $gallery->fetchAll(PDO::FETCH_ASSOC);

// Socials 不需要多语言
$socials = $pdo->prepare("SELECT * FROM {$prefix}companySocials");
$socials->execute();
$company['socials'] = $socials->fetchAll(PDO::FETCH_ASSOC);

// Videos 不需要多语言
$videoStmt = $pdo->prepare("SELECT * FROM {$prefix}companyVideo");
$videoStmt->execute();
$company['videos'] = $videoStmt->fetchAll(PDO::FETCH_ASSOC);

// PDFs 需要多语言
$pdfStmt = $pdo->prepare("SELECT * FROM {$prefix}companyPDFs WHERE language_id = ? ORDER BY created_at DESC");
$pdfStmt->execute([$language_id]);
$company['pdfs'] = $pdfStmt->fetchAll(PDO::FETCH_ASSOC);

// Sections 不需要多语言
$sections = $pdo->prepare("SELECT section_key, status FROM {$prefix}companySections");
$sections->execute();
$sectionStatus = [];
foreach ($sections as $section) {
  $sectionStatus[$section['section_key']] = $section['status'];
}

// --- Fetch Blogs ---
$blogs = $pdo->prepare("
  SELECT * FROM {$prefix}blogs 
  WHERE language_id = ? 
    AND status = 'published' 
    AND created_at <= CONVERT_TZ(NOW(), '+00:00', '+08:00') 
  ORDER BY created_at DESC
");

$blogs->execute([$language_id]);
$company['blogs'] = $blogs->fetchAll(PDO::FETCH_ASSOC);

//fetch carousel & corresponding slides
$stmt = $pdo->prepare("SELECT * FROM {$prefix}companyCarousel WHERE company_id = ? AND language_id =?");
$stmt->execute([$company['id'], $language_id]);
$carousels = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ids = array_column($carousels, 'id');
$cslides = [];
if (!empty($ids)) {
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("SELECT * FROM {$prefix}companyCarouselSlides WHERE carousel_id IN ($placeholders)");
  $stmt->execute($ids);
  $cslides = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function isSectionActive($key, $sectionStatus)
{
  if ($key === 'address') {
    $status = $sectionStatus[$key] ?? 'inactive';
    return ($status === 'active' || $status === 'map-only');
  } else {
    return ($sectionStatus[$key] ?? 'inactive') === 'active';
  }
}

function getCarouselTitle($section, $carousels)
{
  foreach ($carousels as $carousel)
    if ($carousel['section'] === $section)
      return $carousel['title'];
  return null; // nothing matched
}
//get slides for sections where carousel is used
function getCarouselSlides($section, $carousels, $cslides)
{
  $carousel = null;
  foreach ($carousels as $c) {
    if ($c['section'] === $section) {
      $carousel = $c;
      break;
    }
  }
  if (!$carousel)
    return [];
  $carouselId = $carousel['id'];
  $result = [];
  foreach ($cslides as $slide) {
    if ($slide['carousel_id'] == $carouselId) {
      $result[] = [
        'title' => $slide['title'],
        'icon' => $slide['icon'],
        'text' => $slide['text']
      ];
    }
  }
  return $result;
}
?>

<?php
function renderCarousel($sectionName, $carousels, $cslides)
{
  $slides = getCarouselSlides($sectionName, $carousels, $cslides);
  $carouselTitle = getCarouselTitle($sectionName, $carousels);
  if (empty($slides))
    return;
?>
  <div class="carousel-wrapper" data-section="<?= htmlspecialchars($sectionName) ?>">
    <?php if (!empty($carouselTitle)): ?>
      <h3 class="carousel-title"><?= $carouselTitle ?></h3>
    <?php endif; ?>
    <div class="carousel-container">
      <div class="carousel-track">
        <?php foreach ($slides as $slide): ?>
          <div class="carousel-slide">
            <div class="slide-box">
              <?php if (!empty($slide['icon'])): ?>
                <img src="<?= htmlspecialchars($slide['icon']) ?>" alt="icon" class="slide-icon">
              <?php endif;
              if (!empty($slide['title'])): ?>
                <h3 class="slide-title"><?= ($slide['title']) ?></h3>
              <?php endif;
              if (!empty($slide['text'])): ?>
                <p class="slide-text"><?= ($slide['text']) ?></p>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="carousel-dots"></div>
  </div>
<?php } ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company['name'] ?? 'Consultation') ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet" />

    <style>
      /* --- V4 RESET & VARIABLES --- */
      :root {
        --bg-white: #ffffff;
        --text-dark: #111827;
        --text-muted: #6b7280;
        --border-light: rgba(0, 0, 0, 0.05);
        --radius-lg: 24px;
        --nav-glass: rgba(255, 255, 255, 0.85);
      }
      
      /* 限制全局最大宽度并锁死水平溢出 */
      html, body {
        max-width: 100%;
        overflow-x: hidden !important; /* 强制拦截 AOS 动画溢出 */
        position: relative; /* 关键：把动画元素限制在页面边界内 */
      }

      html {
        scroll-behavior: smooth;
      }
      
      *, *::before, *::after {
        box-sizing: border-box;
      }

      body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-white);
        color: var(--text-dark);
        margin: 0;
        padding: 0;
      }

      h1, h2, h3, h4 {
        letter-spacing: -0.02em;
        font-weight: 700;
        margin-top: 0;
      }

      p { margin-top: 0; }

      /* --- CENTERED STICKY NAV --- */
      .glass-nav {
        position: fixed;
        top: 20px;
        left: 0;
        right: 0;
        margin: 0 auto;
  
        width: 90%;
        max-width: 1000px;
        background: var(--nav-glass);
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border: 1px solid var(--border-light);
        border-radius: 50px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 40px;
        z-index: 99999;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        }

      .nav-group {
        display: flex;
        gap: 30px;
        align-items: center;
      }

      .nav-logo {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        justify-content: center;
      }

      .nav-logo img {
        height: 45px;
        object-fit: contain;
      }

      .glass-nav a {
        text-decoration: none;
        color: var(--text-dark);
        font-weight: 500;
        font-size: 15px;
        transition: opacity 0.2s;
      }

      .glass-nav a:hover { opacity: 0.6; }
      
      /* --- LANGUAGE DROPDOWN --- */
      .lang-dropdown {
        position: relative;
        display: inline-block;
        margin-left: 15px;
        padding-left: 15px;
        border-left: 1px solid var(--border-light);
      }

      .lang-dropbtn {
        background: transparent;
        color: var(--text-dark);
        font-weight: 500;
        font-size: 15px;
        border: none;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 0;
        transition: opacity 0.2s;
      }

      .lang-dropbtn:hover {
        opacity: 0.6;
      }

      .lang-dropdown-content {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        margin-top: 15px; /* space between nav and dropdown */
        background-color: #ffffff;
        min-width: 150px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        border-radius: 12px;
        overflow: hidden;
        z-index: 99999;
        border: 1px solid var(--border-light);
      }

      .lang-dropdown-content a {
        color: var(--text-dark);
        padding: 12px 16px;
        text-decoration: none;
        display: block;
        font-size: 14px;
        transition: background 0.2s, color 0.2s;
      }

      .lang-dropdown-content a:hover {
        background-color: #f3f4f6;
        opacity: 1 !important; /* override the glass-nav hover */
      }

      .lang-dropdown-content a.active {
        font-weight: 700;
        color: #00b14f; /* Your active color */
        background-color: #f9fafb;
      }

      /* Show the dropdown menu on hover */
      .lang-dropdown:hover .lang-dropdown-content {
        display: block;
      }

      /* --- HERO SECTION --- */
      .hero-section {
        position: relative;
        width: 100%; 
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
      }

      .hero-bg {
        position: absolute;
        top: 0; left: 0;
        width: 100%; height: 100%;
        object-fit: cover;
        z-index: -2;
      }

      .hero-overlay {
        position: absolute;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.4);
        z-index: -1;
      }

      .hero-content {
        text-align: center;
        color: #ffffff;
        max-width: 800px;
        z-index: 1;
        padding: 20px;
      }

      .hero-content h1 {
        font-size: 64px;
        font-weight: 800;
        margin-bottom: 20px;
        line-height: 1.1;
      }

      .hero-content p {
        font-size: 18px;
        opacity: 0.9;
      }

      /* --- GENERAL SECTIONS --- */
      section {
        padding: 120px 20px;
        max-width: 1200px;
        margin: 0 auto;
      }

      .section-header {
        text-align: center;
        margin-bottom: 60px;
      }

      .section-header h2 {
        font-size: 42px;
        margin-bottom: 15px;
      }

      .section-header p {
        color: var(--text-muted);
        font-size: 18px;
      }

      /* --- ABOUT US --- */
      .about-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 60px;
        align-items: center;
      }

      .about-image {
        border-radius: var(--radius-lg);
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(0,0,0,0.08);
      }

      .about-image img {
        width: 100%;
        height: auto;
        display: block;
      }

      .about-text h2 {
        font-size: 32px;
        margin-bottom: 20px;
      }

      .about-text p {
        color: var(--text-muted);
        line-height: 1.8;
        font-size: 16px;
      }

      /* --- BENTO GRID --- */
      .bento-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        grid-auto-rows: minmax(250px, auto);
      }

      .bento-item {
        background: #f9fafb;
        border-radius: var(--radius-lg);
        padding: 40px;
        border: 1px solid var(--border-light);
        transition: transform 0.3s ease, background 0.3s ease;
        display: flex;
        flex-direction: column;
        justify-content: center;
        overflow: hidden;
      }

      .bento-item:hover {
        transform: translateY(-5px);
        background: #ffffff;
        box-shadow: 0 20px 40px rgba(0,0,0,0.04);
      }

      .bento-item:nth-child(1),
      .bento-item:nth-child(4) {
        grid-column: span 2;
      }

      .bento-icon-small {
        width: 50px;
        height: 50px;
        object-fit: contain;
        margin-bottom: 20px;
        font-size: 40px;
        color: var(--text-dark);
      }

      .bento-image-large {
        width: 100%;
        height: 200px;
        object-fit: cover;
        border-radius: 12px;
        margin-bottom: 20px;
      }

      .bento-item p {
        color: var(--text-muted);
        line-height: 1.6;
        font-size: 15px;
      }

      /* --- VIDEO DEMO & CONTACT --- */
      .demo-container {
        background: #111827;
        border-radius: var(--radius-lg);
        padding: 80px 40px;
        text-align: center;
        color: white;
      }

      .demo-container h2 { color: white; }
      .demo-container p { color: #9ca3af; margin-bottom: 40px; }

      .video-wrapper {
        position: relative;
        padding-bottom: 56.25%;
        height: 0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(0,0,0,0.5);
      }

      .video-wrapper iframe, .video-wrapper video {
        position: absolute;
        top: 0; left: 0;
        width: 100%; height: 100%;
        border: 0;
      }

      .footer-section {
        background: #ffffff;
        border-top: 1px solid var(--border-light);
        padding: 80px 20px 40px;
        margin-top: 50px;
      }

      .footer-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 60px;
        max-width: 1200px;
        margin: 0 auto;
      }

      /* --- WHATSAPP FLOAT --- */
      .whatsapp-float {
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 60px;
        height: 60px;
        background-color: #25d366;
        border-radius: 50%;
        box-shadow: 0 10px 20px rgba(37, 211, 102, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        animation: pulse-wa 2s infinite;
        transition: transform 0.3s;
      }

      .whatsapp-float:hover { transform: scale(1.1); }
      .whatsapp-float img { width: 32px; height: 32px; filter: brightness(0) invert(1); }

      @keyframes pulse-wa {
        0% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.5); }
        70% { box-shadow: 0 0 0 15px rgba(37, 211, 102, 0); }
        100% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); }
      }

      .hamburger-btn {
        display: none;
        background: none;
        border: none;
        color: var(--text-dark);
        cursor: pointer;
        padding: 5px;
      }
      
    /* --- MOBILE RESPONSIVENESS --- */
    @media (max-width: 768px) {
    .glass-nav {
        padding: 12px 20px; /* Slightly reduced padding */
        width: calc(100% - 40px); /* Keeps exactly 20px space on both sides */
        max-width: 100%;
        flex-wrap: wrap; 
        transition: all 0.4s ease-in-out;
    }

    .glass-nav.nav-open {
        background: rgba(255, 255, 255, 0.98);
        border-radius: 24px;
    }

    .nav-logo {
        position: static;
        transform: none;
        margin-right: auto;
    }

    .hamburger-btn {
        display: block;
    }

    /* 1. Setup for Slide Down */
    .nav-group {
        width: 100%;
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    
    /* Hide using max-height instead of display: none */
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        padding-top: 0;
        padding-bottom: 0;
        margin: 0;
        border-top: 1px solid transparent; /* invisible border for transition */
        transition: max-height 0.4s ease-in-out, opacity 0.3s ease-in-out, padding 0.4s ease-in-out;
    }

    /* 2. Open State - Slide Down */
    .glass-nav.nav-open .nav-group {
        max-height: 400px; /* Expands to fit content */
        opacity: 1;
        padding-bottom: 10px;
    }

    /* 3. Divider line specific to the right group, animated in */
    .glass-nav.nav-open .nav-group.right-group {
        border-top: 1px solid var(--border-light);
        padding-top: 15px;
        margin-top: 5px;
    }

    /* Language dropdown mobile fixes */
    .lang-dropdown {
        margin-left: 0;
        padding-left: 0;
        border-left: none;
        width: 100%;
    }
  
    .lang-dropdown-content {
        position: static;
        box-shadow: none;
        border: 1px solid var(--border-light);
        background: #f9fafb;
        margin-top: 10px;
        width: 100%;
    }

    /* Existing layout grid adjustments */
    .about-container, .footer-grid, .bento-grid { grid-template-columns: 1fr; }
    .bento-item:nth-child(1), .bento-item:nth-child(4) { grid-column: span 1; }
    .hero-content h1 { font-size: 40px; }
    }
    
    </style>
</head>
<body>

  <nav class="glass-nav" data-aos="fade-down" data-aos-duration="1000">
    <div class="nav-logo">
      <img src="<?= htmlspecialchars($company['logo'] ?? '') ?>" alt="<?= htmlspecialchars($company['name'] ?? 'Logo') ?>">
    </div>

    <button class="hamburger-btn" onclick="toggleNavMenu()">
      <svg viewBox="0 0 24 24" width="28" height="28" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
      </svg>
    </button>

    <div class="nav-group">
      <a href="#home" onclick="closeNavMenu()">Home</a>
      <a href="#about" onclick="closeNavMenu()">About Us</a>
      <a href="#features" onclick="closeNavMenu()">Why Choose Us</a>
    </div>
  
    <div class="nav-group right-group">
      <a href="#provide" onclick="closeNavMenu()">Services</a>
      <a href="#contact" onclick="closeNavMenu()">Contact</a>
    
      <div class="lang-dropdown">
        <button class="lang-dropbtn">
          <?php
            if ($language_id == 1) echo 'BM';
            else echo 'EN';
          ?> 
          <span style="font-size: 10px;">▼</span>
        </button>
        <div class="lang-dropdown-content">
          <a href="?lang=2" class="<?= $language_id == 2 ? 'active' : '' ?>" onclick="closeNavMenu()">English (EN)</a>
          <a href="?lang=1" class="<?= $language_id == 1 ? 'active' : '' ?>" onclick="closeNavMenu()">Bahasa Melayu (BM)</a>
        </div>
      </div>
    </div>
  </nav>

  <section id="home" class="hero-section">
    <?php $bg_video = $company['bg_video'] ?? null; ?>
    <?php if (!empty($bg_video)): ?>
      <video class="hero-bg" autoplay loop muted playsinline>
        <source src="<?= htmlspecialchars($bg_video) ?>" type="video/mp4">
      </video>
    <?php elseif (!empty($company['banners'])): ?>
      <img src="<?= htmlspecialchars($company['banners'][0]) ?>" class="hero-bg" alt="Hero">
    <?php endif; ?>
    <div class="hero-overlay"></div>

    <div class="hero-content" data-aos="fade-up" data-aos-duration="1200">
      <h1><?= htmlspecialchars($company['banner_caption'] ?? $company['name']) ?></h1>
      <p>Consultation & Services designed for the modern era.</p>
    </div>
  </section>

  <section id="about">
    <div class="about-container">
      <div class="about-image" data-aos="fade-right">
        <img src="<?= !empty($company['gallery']) ? htmlspecialchars($company['gallery'][0]['image_path']) : 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&q=80&w=800' ?>" alt="About Us">
      </div>
      <div class="about-text" data-aos="fade-left">
        <h2><?= $company['about_title'] ?? 'About Us' ?></h2>
        <div><?= html_entity_decode($company['about_description'] ?? '') ?></div>
      </div>
    </div>
  </section>

  <section id="features">
    <div class="section-header" data-aos="fade-up">
      <h2><?= $company['features_title'] ?? 'Why Choose Us' ?></h2>
    </div>
    <div class="bento-grid">
      <?php if (!empty($company['features'])): ?>
        <?php foreach (array_slice($company['features'], 0, 5) as $feature): ?>
          <div class="bento-item" data-aos="fade-up">
            <?php if (!empty($feature['icon'])): ?>
              <?php if (strpos($feature['icon'], '.') !== false || strpos($feature['icon'], '/') !== false): ?>
                <img src="<?= htmlspecialchars($feature['icon']) ?>" alt="Icon" class="bento-icon-small">
              <?php else: ?>
                <i class="<?= htmlspecialchars($feature['icon']) ?> bento-icon-small"></i>
              <?php endif; ?>
            <?php endif; ?>
            <h4><?= strip_tags(html_entity_decode($feature['title'])) ?></h4>
            <p><?= strip_tags(html_entity_decode($feature['description'] ?? '')) ?></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section id="provide" style="background-color: #fafafa; max-width: 100%; border-radius: 40px; margin-bottom: 50px;">
    <div style="max-width: 1200px; margin: 0 auto; padding: 100px 20px;">
      
      <div class="section-header" data-aos="fade-up">
        <h2><?= $company['provide_title'] ?? 'Our Services' ?></h2>
        <div><?= html_entity_decode($company['provide_text'] ?? '') ?></div>
      </div>

      <div class="bento-grid">
        <?php if (!empty($company['provide'])): ?>
          <?php foreach (array_slice($company['provide'], 0, 5) as $service): ?>
            <div class="bento-item" data-aos="fade-up" style="background: #ffffff;">
              <?php if (!empty($service['icon']) && (strpos($service['icon'], '.') !== false || strpos($service['icon'], '/') !== false)): ?>
                <img src="<?= htmlspecialchars($service['icon']) ?>" alt="Service" class="bento-image-large">
              <?php elseif(!empty($service['icon'])): ?>
                <i class="<?= htmlspecialchars($service['icon']) ?> bento-icon-small"></i>
              <?php endif; ?>
              
              <h4><?= strip_tags(html_entity_decode($service['title'])) ?></h4>
              
              <p><?= strip_tags(html_entity_decode($service['description'] ?? '')) ?></p>
              
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      
    </div>
  </section>

  <section id="video">
    <div class="demo-container" data-aos="zoom-in">
      <h2><?= $company['video_title'] ?? 'Demo' ?></h2>
      <div class="video-wrapper">
        <?php if (!empty($company['videos'])): ?>
          <?= html_entity_decode($company['videos'][0]['iframe_code']) ?>
        <?php else: ?>
          <iframe src="https://www.youtube.com/embed/ScMzIvxBSi4?autoplay=0&controls=1" title="Demo Video" allowfullscreen></iframe>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <footer id="contact" class="footer-section">
    <div class="footer-grid">
      <div data-aos="fade-right">
        <h2 style="font-size: 36px; margin-bottom: 20px;">Get in Touch</h2>
        <p style="color: var(--text-muted); margin-bottom: 30px;">Ready to start your next project? Contact us today for a consultation.</p>
        
        <div style="margin-bottom: 15px;">
          <strong>Email:</strong> <br>
          <a href="mailto:<?= htmlspecialchars($company['email'] ?? '') ?>" style="color: var(--text-dark); text-decoration: none;"><?= htmlspecialchars($company['email'] ?? '') ?></a>
        </div>
        <div style="margin-bottom: 15px;">
          <strong>Phone:</strong> <br>
          <a href="tel:<?= htmlspecialchars($company['phone'] ?? '') ?>" style="color: var(--text-dark); text-decoration: none;"><?= htmlspecialchars($company['phone'] ?? '') ?></a>
        </div>
        <div>
          <strong>Address:</strong> <br>
          <span style="color: var(--text-muted);"><?= nl2br(htmlspecialchars($company['address'] ?? '')) ?></span>
        </div>
      </div>
      
      <div data-aos="fade-left" style="background: #f9fafb; padding: 40px; border-radius: var(--radius-lg);">
        <form action="" method="POST" style="display: flex; flex-direction: column; gap: 20px;">
          <input type="text" placeholder="Your Name" style="padding: 15px; border-radius: 8px; border: 1px solid var(--border-light); font-family: 'Inter', sans-serif;">
          <input type="email" placeholder="Your Email" style="padding: 15px; border-radius: 8px; border: 1px solid var(--border-light); font-family: 'Inter', sans-serif;">
          <textarea placeholder="Message" rows="4" style="padding: 15px; border-radius: 8px; border: 1px solid var(--border-light); font-family: 'Inter', sans-serif;"></textarea>
          <button type="submit" style="background: var(--text-dark); color: white; padding: 15px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">Send Message</button>
        </form>
      </div>
    </div>
  </footer>

  <?php 
    $whatsapp_url = '';
    if (!empty($company['socials'])) {
      foreach ($company['socials'] as $social) {
        if (strtolower($social['name']) === 'whatsapp') {
          $whatsapp_url = $social['link_url'];
          break;
        }
      }
    }
  ?>
  <?php if (!empty($whatsapp_url)): ?>
    <a href="<?= htmlspecialchars($whatsapp_url) ?>" class="whatsapp-float" target="_blank" data-aos="zoom-in" data-aos-delay="500">
      <img src="https://cdn-icons-png.flaticon.com/512/733/733585.png" alt="WhatsApp">
    </a>
  <?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
  <script>
    AOS.init({
      duration: 1000,
      once: true
    });
  </script>
  <script>
  // Mobile Nav Toggle Functions
  function toggleNavMenu() {
    document.querySelector('.glass-nav').classList.toggle('nav-open');
  }

  function closeNavMenu() {
    document.querySelector('.glass-nav').classList.remove('nav-open');
  }
  </script>
  
</body>
</html>
