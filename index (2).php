<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$domain = $_SERVER['HTTP_HOST'];
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auto_update_db.php';
require_once __DIR__ . '/../auto_sync_data.php';

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
// $gallery = $pdo->prepare("SELECT image_path FROM {$prefix}companyGallery");
// $gallery->execute();
// $company['gallery'] = $gallery->fetchAll(PDO::FETCH_COLUMN);
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
  SELECT * 
  FROM {$prefix}blogs 
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

<?php
function isMobileDevice() {
  $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
  return preg_match('/iphone|ipad|ipod|android|mobile|silk|kindle|blackberry|opera mini/', $ua);
}

$heroHeight = isMobileDevice() ? '55vh' : '100vh';   // <-- change 55vh to what you like
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title><?= $company['meta_title'] ?: $company['name'] ?></title>
  <meta name="description" content="<?= $company['meta_description'] ?>">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
  <link href="/v4/main.css?v=<?php echo time(); ?>" rel="stylesheet">
  
<style>
/* ===== Header Redesign A1: Dark Floating Bar ===== */
header, .navbar, .site-header, .topbar {
  position: sticky !important;
  top: 0 !important;
  z-index: 9999 !important;
  background: rgba(17,24,39,.92) !important;
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(255,255,255,.08) !important;
}

/* inner container */
header .container, .navbar .container, .site-header .container,
header .main-content, .navbar .main-content, .site-header .main-content {
  padding-top: 10px !important;
  padding-bottom: 10px !important;
}

/* links */
header a, .navbar a, .site-header a, .topbar a {
  color: rgba(255,255,255,.92) !important;
  font-weight: 650 !important;
  text-decoration: none !important;
}

/* pill nav */
header nav a, .navbar nav a, .site-header nav a,
header .nav a, .navbar .nav a, .site-header .nav a {
  padding: 10px 12px !important;
  border-radius: 999px !important;
}

/* hover + active */
header nav a:hover, .navbar nav a:hover, .site-header nav a:hover,
header .nav a:hover, .navbar .nav a:hover, .site-header .nav a:hover {
  background: rgba(255,255,255,.10) !important;
}

header nav a.active, .navbar nav a.active, .site-header nav a.active,
header .nav a.active, .navbar .nav a.active, .site-header .nav a.active {
  background: rgba(255,255,255,.16) !important;
}

/* dropdown/select */
header select, .navbar select, .site-header select {
  background: rgba(255,255,255,.10) !important;
  color: #fff !important;
  border: 1px solid rgba(255,255,255,.18) !important;
  border-radius: 12px !important;
  padding: 8px 10px !important;
}

/* logo if exists */
header img, .navbar img, .site-header img {
  border-radius: 10px !important;
}

/* mobile */
@media (max-width:768px){
  header, .navbar, .site-header { padding-left: 8px !important; padding-right: 8px !important; }
}
</style>

<style>
/* ===== Force header nav icons to light color ===== */

/* 1) font-icon <i> */
header nav a i,
.navbar nav a i,
.site-header nav a i,
header .nav a i,
.navbar .nav a i {
  color: #e5e7eb !important;
}

/* 2) inline svg */
header nav a svg,
.navbar nav a svg,
.site-header nav a svg,
header .nav a svg,
.navbar .nav a svg {
  fill: #e5e7eb !important;
  stroke: #e5e7eb !important;
}

/* if svg uses currentColor, force it */
header nav a svg * ,
.navbar nav a svg * {
  fill: currentColor !important;
  stroke: currentColor !important;
}

/* 3) image icons (png/svg as <img>) — make them white via filter */
header nav a img,
.navbar nav a img,
.site-header nav a img,
header .nav a img,
.navbar .nav a img {
  filter: invert(1) grayscale(1) brightness(1.2) !important;
  opacity: .95 !important;
}

/* hover become brighter */
header nav a:hover i,
header nav a:hover svg,
header nav a:hover img,
.navbar nav a:hover i,
.navbar nav a:hover svg,
.navbar nav a:hover img {
  opacity: 1 !important;
  color: #ffffff !important;
  fill: #ffffff !important;
  stroke: #ffffff !important;
  filter: invert(1) grayscale(0) brightness(1.35) !important;
}
</style>

<style>
/* =========================
   DESKTOP ONLY (>= 769px)
   ========================= */
@media (min-width: 769px) {
  /* Keep your dark header look */
  header, .navbar, .site-header, .topbar {
    background: rgba(17,24,39,.92) !important;
    border-bottom: 1px solid rgba(255,255,255,.08) !important;
  }

  /* Desktop: light text/icons */
  header a, .navbar a, .site-header a,
  header i, .navbar i, .site-header i,
  header svg, .navbar svg, .site-header svg {
    color: #e5e7eb !important;
    fill: #e5e7eb !important;
    stroke: #e5e7eb !important;
  }

  /* Desktop: language select visible */
  header select, .navbar select, .site-header select {
    background: rgba(255,255,255,0.12) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255,255,255,0.25) !important;
  }

  header select option, .navbar select option, .site-header select option {
    background: #111827 !important;
    color: #ffffff !important;
  }
}

  /* Mobile: language dropdown MUST be dark text on light bg */
  header select, .navbar select, .site-header select {
    background: #ffffff !important;
    color: #111827 !important;
    border: 1px solid rgba(0,0,0,0.15) !important;
  }

  header select option, .navbar select option, .site-header select option {
    background: #ffffff !important;
    color: #111827 !important;
  }

  /* If your mobile language menu is a custom dropdown */
  header .dropdown-menu, .navbar .dropdown-menu {
    background: #ffffff !important;
    color: #111827 !important;
    border: 1px solid rgba(0,0,0,0.12) !important;
  }

  header .dropdown-menu a, .navbar .dropdown-menu a {
    color: #111827 !important;
  }
}
</style>
<style>
/* =========================
   MOBILE MENU ICON COLOR OVERRIDE
   ========================= */
@media (max-width: 768px){
  /* ONLY icons inside the slide-out menu */
  #mainNav img,
  #mainNav svg,
  #mainNav i{
    filter: none !important;      /* cancel invert */
    color: #111827 !important;    /* dark text */
    fill: #111827 !important;     /* svg */
    stroke: #111827 !important;
    opacity: 1 !important;
  }
}
</style>
<style>
/* =========================
   MOBILE MENU (slide in / out)
   ========================= */
@media (max-width: 768px){

  #mainNav{
    position: fixed !important;
    top: 0;
    left: 0;
    height: 100vh !important;

    width: 55vw !important;
    max-width: 280px !important;

    background: #ffffff !important;
    z-index: 10000 !important;

    /* animation */
    transform: translateX(-100%);
    transition: transform 0.5s ease;
    will-change: transform;

    display: block !important; /* keep visible for animation */
  }

  #mainNav.show,
  #mainNav.active,
  #mainNav.open{
    transform: translateX(0);
  }
}
</style>
<style>
/* ===== overflow-x protection (keep sticky working) ===== */
html, body{
  max-width: 100%;
  overflow-x: clip;
}

/* fallback if clip not supported */
@supports not (overflow: clip){
  html, body{ overflow-x: hidden; }
}
</style>
<style>
/* =========================
   FOOTER REVEAL (SAFE)
   - footer stays visible if JS fails
   - reveal only once (JS disconnect)
   ========================= */

/* default: footer visible */
footer#contact{
  opacity: 1;
  transform: none;
}

/* only hide footer when JS confirms animation is ready */
.footer-anim-ready footer#contact{
  opacity: 0;
  transform: translateY(28px);
  transition: opacity 700ms ease, transform 700ms ease;
  will-change: opacity, transform;
}

/* reveal state */
.footer-anim-ready footer#contact.footer-reveal{
  opacity: 1;
  transform: translateY(0);
}
</style>

  
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="<?= htmlspecialchars($company['logo']) ?>" type="image/x-icon">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
  <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet" />
  <?= $company['header_script'] ?? '' ?> <!-- 这里插入 Header Script -->

  <!-- 基本 Open Graph 标签 -->
  <meta property="og:title" content="<?= htmlspecialchars($company['meta_title'] ?: $company['name']) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($company['meta_description']) ?>">
  <meta property="og:image"
    content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') ?>://<?= htmlspecialchars($domain) ?>/<?= ltrim($company['logo'], '/') ?>">
  <meta property="og:url" content="http://<?= htmlspecialchars($domain) ?>">
  <meta property="og:type" content="website">
</head>

<body style="max-width:100%;">
  <?= $company['body_script'] ?? '' ?> <!-- 这里插入 Body Script -->

  <?php 
  // 1. Turn on "recording" mode. Nothing prints to the screen yet.
  ob_start();
  
  // 2. Load the original header file.
  include __DIR__ . '/../header.php'; 
  
  // 3. Stop recording and save everything into a variable called $header_content.
  $header_content = ob_get_clean();
  
  // 4. Search for the link to "header.css" and replace it with nothing (delete it).
  // This removes the V3 styling that is breaking your design.
  $header_content = preg_replace('/<link[^>]+header\.css[^>]*>/i', '', $header_content);
  
  // 5. Finally, print the cleaned-up header to the screen.
  echo $header_content; 
  ?>
  <div class="menu-overlay" id="menuOverlay" onclick="toggleMenu()"></div>
  <div id="pageContent" style="max-width:100%;">

    <?php if (!empty($company['banners'])): ?>
  <div class="banner-slider" data-aos="fade-in"
       style="height:<?= $heroHeight ?? '100vh' ?>; border-radius:0; width:100%; overflow:hidden; position:relative;">

    <div class="banner-slides" style="width:100%; height:100%; position:relative; overflow:hidden;">
      <?php foreach ($company['banners'] as $index => $banner): ?>
        <div class="banner-slide<?= $index === 0 ? ' active' : '' ?>"
             style="position:absolute; inset:0; width:100%; height:100%; <?= $index === 0 ? 'display:block;' : 'display:none;' ?>">
          <img src="<?= htmlspecialchars($banner) ?>" alt="Banner Image"
               style="display:block; width:100%; height:100%; object-fit:cover; border-radius:0;">
        </div>
      <?php endforeach; ?>
    </div>

    <!-- keep dots for JS, but hide them -->
    <div class="banner-dots" style="display:none;">
      <?php foreach ($company['banners'] as $index => $banner): ?>
        <span class="dot<?= $index === 0 ? ' active' : '' ?>" data-slide="<?= $index ?>"></span>
      <?php endforeach; ?>
    </div>

  </div>
<?php endif; ?>


    <!-- Editorial Intro (Option C) -->
<section class="section" style="padding-top:28px; padding-bottom:28px;">
  <div class="main-content" style="max-width:980px; margin:0 auto; text-align:left;">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:18px; flex-wrap:wrap;">
      <h1 style="margin:0; font-size:clamp(26px,3.6vw,44px); line-height:1.1; color:#111827;">
        <?= htmlspecialchars($company['name']) ?>
      </h1>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="#features-full-width" style="padding:10px 14px; border-radius:999px; background:#111827; color:#fff; font-weight:600; text-decoration:none;">
          Explore
        </a>
        <a href="#contact" style="padding:10px 14px; border-radius:999px; border:1px solid rgba(0,0,0,0.15); background:#fff; font-weight:600; text-decoration:none; color:#111827;">
          Contact
        </a>
      </div>
    </div>

    <?php if (!empty($company['banner_caption'])): ?>
      <p style="margin:12px 0 0; color:#374151; font-size:15px;">
        <?= ($company['banner_caption']) ?>
      </p>
    <?php endif; ?>

    <?php if (!empty($company['meta_description'])): ?>
      <p style="margin:10px 0 0; color:#6b7280; font-size:14px; line-height:1.8;">
        <?= htmlspecialchars($company['meta_description']) ?>
      </p>
    <?php endif; ?>
  </div>
</section>

<?php if (isSectionActive('about', $sectionStatus)): ?>
        <section id="about" class="section about-wrapper" data-aos="fade-up">
          <div class="about-left" data-aos="fade-right">
            <h2><?= $company['about_title'] ?></h2>
            <div class="about-description">
                <?php 
                $desc = $company['about_description'];
    
                // 1. Remove paragraphs that contain only a "non-breaking space" (The usual culprit)
                $desc = str_replace('<p>&nbsp;</p>', '', $desc);
    
                // 2. Remove paragraphs that contain only a line break
                $desc = str_replace('<p><br></p>', '', $desc);
    
                // 3. Remove standard empty paragraphs
                $desc = preg_replace('/<p>\s*<\/p>/', '', $desc);
    
                echo $desc; 
                ?>
            </div>
          </div>
          <?php if (!empty($company['about_image'])): ?>
            <div class="about-right" data-aos="fade-left">
              <img src="<?= htmlspecialchars($company['about_image']) ?>" alt="About Image">
            </div>
          <?php endif; ?>
        </section>
        <div id="about-carousel" data-aos="fade-up">
          <?php renderCarousel('about', $carousels, $cslides); ?>
        </div>
      <?php endif; ?>

<div class="main-content">
    
    <!-- Editorial break -->
<section class="section" style="padding-top:100px; padding-bottom:0px;">
  <div class="main-content" style="max-width:920px; margin:0 auto; text-align:left;">
  </div>
</section>
      
<?php if (isSectionActive('features', $sectionStatus)): ?>
    </div> 

    <style>
      #features-full-width {
        width: 100%;
        background-color: #ffffff; /* White background extends to edges */
        padding: 80px 0;
        /* Optional: Add a top border if you want a subtle separation from the section above */
        /* border-top: 1px solid #f3f4f6; */
      }
      
      .features-container-inner {
        max-width: 1280px; /* Keep content aligned with the rest of the site */
        margin: 0 auto;
        padding: 0 24px;
        display: grid;
        gap: 60px;
        grid-template-columns: 1fr; 
      }

      /* Desktop: Split Layout (Title Left, Content Right) */
      @media (min-width: 992px) {
        .features-container-inner {
          grid-template-columns: 320px 1fr; 
          gap: 80px;
          align-items: start;
        }
        .features-left {
          position: sticky;
          top: 120px;
        }
      }

      /* Title Styling */
      .features-left h2 {
        font-size: clamp(2.2rem, 5vw, 3rem);
        font-weight: 800;
        color: #111;
        margin: 0;
        line-height: 1.1;
      }
      
      /* The black line above the title */
      .title-line-accent {
        width: 60px;
        height: 5px;
        background-color: #111827;
        margin-bottom: 24px;
      }

      /* Right Side Grid */
      .features-right-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 50px;
        row-gap: 70px;
      }

      /* Individual Feature Item */
      .feature-item-clean {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
      }

      /* Icon Styling */
      .feature-icon-circle {
        width: 100px;
        height: 100px;
        background: #f9fafb; /* Light grey circle */
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
        transition: background 0.3s ease;
      }
      
      /* Hover Effect */
      .feature-item-clean:hover .feature-icon-circle {
        background: #111827; /* Dark on hover */
      }
      .feature-item-clean:hover .feature-icon-circle img {
        filter: brightness(0) invert(1); /* White icon on hover */
      }
      
      .feature-icon-circle img {
        width: 48px;
        height: 48px;
        object-fit: contain;
        transition: filter 0.3s ease;
      }

      .feature-item-clean h3 {
        font-size: 1.25rem;
        font-weight: 700;
        color: #111;
        margin-bottom: 12px;
      }

      .feature-item-clean p {
        font-size: 1rem;
        line-height: 1.6;
        color: #6b7280;
        margin: 0;
      }
    </style>

    <div id="features"></div>
    <section id="features-full-width">
      <div class="features-container-inner">
        
        <div class="features-left" data-aos="fade-right">
          <div class="title-line-accent"></div>
          <h2><?= strip_tags($company['features_title']) ?></h2>
        </div>

        <div class="features-right-grid">
          <?php foreach ($company['features'] as $index => $f): ?>
            <?php if (!empty($f['title']) || !empty($f['description'])): ?>
              <div class="feature-item-clean" data-aos="fade-up" data-aos-delay="<?= $index * 100 ?>">
                <?php if (!empty($f['icon'])): ?>
                  <div class="feature-icon-circle">
                    <img src="<?= htmlspecialchars($f['icon']) ?>" alt="Icon">
                  </div>
                <?php endif; ?>
                <h3><?= $f['title'] ?></h3>
                <p><?= $f['description'] ?></p>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

      </div>
    </section>

    <div id="features-carousel" data-aos="fade-up">
      <?php renderCarousel('features', $carousels, $cslides); ?>
    </div>

    <div class="main-content">
<?php endif; ?>


      <!-- Editorial break -->
<section class="section" style="padding-top:0px; padding-bottom:0px;">
  <div class="main-content" style="max-width:920px; margin:0 auto; text-align:left;">
  </div>
</section>

<?php if (isSectionActive('provide', $sectionStatus)): ?>
        <section id="provide" class="section" data-aos="fade-up">
          <div class="provide-wrapper">
            <div class="provide-left" data-aos="fade-right">
              <h2><?= $company['provide_title'] ?></h2>
              <div><?= $company['provide_text'] ?></div>
            </div>
            <div class="provide-right">
              <div class="provide-grid">
                <?php foreach ($company['provide'] as $index => $item): ?>
                  <div class="provide-box" data-aos="fade-up" data-aos-delay="<?= $index * 100 ?>">
                    <?php if (!empty($item['icon'])): ?>
                      <img src="<?= htmlspecialchars($item['icon']) ?>" alt="Icon" class="box-icon">
                    <?php endif; ?>
                    <h3><?= $item['title'] ?></h3>
                    <p><?= $item['description'] ?></p>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </section>
        <div id="provide-carousel" data-aos="fade-up">
          <?php renderCarousel('provide', $carousels, $cslides); ?>
        </div>
      <?php endif; ?>

      <!-- Editorial break -->
<section class="section" style="padding-top:0px; padding-bottom:0px;">
  <div class="main-content" style="max-width:920px; margin:0 auto; text-align:left;">
  </div>
</section>

      <!-- Editorial break -->
<section class="section" style="padding-top:0px; padding-bottom:20px;">
  <div class="main-content" style="max-width:920px; margin:0 auto; text-align:left;">
  </div>
</section>

<?php if (isSectionActive('gallery', $sectionStatus)): ?>
        <section id="gallery" class="section gallery-section" data-aos="fade-up">
          <h2><?= $company['gallery_title'] ?></h2>
          <div class="gallery-grid">
            <?php foreach ($company['gallery'] as $gallery): ?>
              <a href="<?= htmlspecialchars($gallery['image_path']) ?>" class="glightbox" data-gallery="company-gallery"
                data-width="900px" data-height="600px" data-description="<?= $gallery['caption'] ?? '' ?>"
                data-aos="zoom-in">
                <img src="<?= htmlspecialchars($gallery['image_path']) ?>" alt="Gallery Image">
              </a>
            <?php endforeach; ?>
          </div>
          <div id="gallery-carousel" data-aos="fade-up">
            <?php renderCarousel('gallery', $carousels, $cslides); ?>
          </div>
        </section>
        <!-- GLightbox JS -->
        <script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js"></script>
        <script>
          const lightbox = GLightbox({
            selector: '.glightbox',
            loop: true,
            touchNavigation: true,
            closeButton: true,
            zoomable: true,
            autoplayVideos: false
          });
        </script>
      <?php endif; ?>

      <?php if (isSectionActive('video', $sectionStatus) && !empty($company['videos'])): ?>
        <section id="video" class="section" data-aos="fade-up">
          <h2><?= $company['video_title'] ?></h2>

          <div class="video-section">
            <?php foreach ($company['videos'] as $index => $video): ?>
              <div class="video-item" data-aos="fade-in" data-aos-delay="<?= $index * 100 ?>">
                <div class="video-thumb">
                  <?php if (!empty($video['video_link'])): ?>
                    <iframe src="<?= htmlspecialchars($video['video_link']) ?>" allowfullscreen></iframe>
                    <!--<iframe src="<?= htmlspecialchars(!empty($video['video_link']) ? $video['video_link'] : $video['video_file']) ?>" allowfullscreen></iframe>-->
                  <?php elseif (!empty($video['video_file'])): ?>
                    <video controls height="200">
                      <source src="<?= htmlspecialchars($video['video_file']) ?>" type="video/mp4">
                      <source src="<?= htmlspecialchars($video['video_file']) ?>" type="video/webm">
                      <source src="<?= htmlspecialchars($video['video_file']) ?>" type="video/ogg">
                      Your browser does not support the video tag.
                    </video>
                  <?php endif; ?>
                </div>
                <div class="video-content">
                  <h3><?= htmlspecialchars($video['title']) ?></h3>
                  <div class="video-meta">
                    <span><?= date('d M Y', strtotime($video['date'])) ?></span>
                    <button type="button" class="video-fav-btn">♡</button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
        <div id="videos-carousel" data-aos="fade-up">
          <?php renderCarousel('video', $carousels, $cslides); ?>
        </div>
      <?php endif; ?>

      <?php if (isSectionActive('blog', $sectionStatus) && !empty($company['blogs'])): ?>
        <section id="blog" class="section blog-section" data-aos="fade-up">
          <div class="blog-header">
            <h2 class="blog-title"><?= ($company['blog_title'] ?? 'Latest News & Insights') ?></h2>
            <p class="blog-subtitle">
              <?= ($company['blog_sub_title'] ?? 'Stay updated with our latest articles and stories.') ?>
            </p>
          </div>

          <div class="blog-slider">
            <button class="blog-slider-btn prev">‹</button>
            <div class="blog-track-container">
              <div class="blog-track">
                <?php foreach ($company['blogs'] as $blog): ?>
                  <div class="blog-card">
                    <div class="blog-image">
                      <?php if (!empty($blog['image'])): ?>
                        <img src="<?= 'uploads/blogs/' . htmlspecialchars($blog['image']) ?>"
                          alt="<?= htmlspecialchars($blog['title']) ?>">
                      <?php endif; ?>
                    </div>
                    <div class="blog-content">
                      <h3 class="blog-card-title"><?= htmlspecialchars($blog['title']) ?></h3>
                      <p class="blog-excerpt">
                        <?= htmlspecialchars(mb_strimwidth(strip_tags($blog['content']), 0, 120, '...')) ?>
                      </p>
                      <div class="blog-meta">
                        <span class="blog-date"><?= date('d M Y', strtotime($blog['created_at'])) ?></span>
                        <a href="blog.php?id=<?= $blog['id'] ?>&lang=<?= $language_id ?>" class="blog-readmore">Read More
                          →</a>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <button class="blog-slider-btn next">›</button>
          </div>

          <!-- <div class="blog-viewall" data-aos="fade-up">
            <a href="blogs.php?lang=<?= $language_id ?>" class="btn-viewall">View All Articles</a>
          </div> -->
        </section>

      <?php endif; ?>

      <?php if (!empty($company['pdfs'])): ?>
        <section id="pdf" class="section pdf-section" data-aos="fade-up">
          <h2><?= ($company['pdf_title'] ?? 'PDF Files') ?></h2>
          <div class="pdf-list">
            <?php foreach ($company['pdfs'] as $pdf): ?>
              <div class="pdf-item" data-aos="fade-up">
                <h3><?= htmlspecialchars($pdf['title']) ?></h3>
                <embed src="<?= htmlspecialchars($pdf['pdf_file']) ?>" type="application/pdf" class="pdf-preview" />
                <a href="<?= htmlspecialchars($pdf['pdf_file']) ?>" download class="pdf-download-btn">
                  Download PDF ↓
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if (isSectionActive('address', $sectionStatus) && !empty($company['address'])): ?>
        <section id="map-review" class="section map-review-section" data-aos="fade-up">
          <div class="map-container" data-aos="zoom-in">
            <iframe width="100%" height="400" style="border:0;" loading="lazy" allowfullscreen
              referrerpolicy="no-referrer-when-downgrade"
              src="https://www.google.com/maps?q=<?= urlencode($company['address']) ?>&output=embed">
            </iframe>
          </div>
          <?php if (($sectionStatus['address'] ?? '') !== 'map-only'): ?>
            <div class="map-link" style="margin-top:15px; text-align:center;">
              <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($company['address']) ?>"
                target="_blank" style="color:#0066cc; font-weight:bold;">
                👉 Check comments on Google Maps
              </a>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <a href="https://wa.me/60123456789" target="_blank" class="whatsapp-float">
        <img src="img/contact.png" alt="WhatsApp">
        </a>

      <div class="social-buttons" id="socialButtons">
        <?php foreach ($company['socials'] as $social): ?>
          <a href="<?= htmlspecialchars($social['link_url']) ?>" target="_blank"
            class="social-btn <?= strtolower($social['name']) ?>" title="<?= htmlspecialchars($social['name']) ?>"
            data-aos="zoom-in">
            <img src="<?= htmlspecialchars($social['icon_path']) ?>" alt="<?= htmlspecialchars($social['name']) ?>">
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php 
        // 1. Turn on recording mode.
        ob_start();
  
        // 2. Load the original footer file.
        include __DIR__ . '/../footer.php'; 
  
        // 3. Save the footer content into a variable.
        $footer_content = ob_get_clean();
  
        // 4. Search for the link to "footer.css" and delete it.
        $footer_content = preg_replace('/<link[^>]+footer\.css[^>]*>/i', '', $footer_content);
  
        // 5. Print the cleaned-up footer.
        echo $footer_content; 
    ?>
  </div>
  
<script>
document.addEventListener("DOMContentLoaded", function () {
  const footerNav = document.querySelector(".footer-nav");
  if (!footerNav) return;

  const featuresLink = footerNav.querySelector('a[href="#features"]');
  const servicesLink = footerNav.querySelector('a[href="#provide"]');

  // 1) Fix order: Features BEFORE Services
  if (featuresLink && servicesLink) {
    servicesLink.parentNode.insertBefore(featuresLink, servicesLink);
  }

  // Helper: find the real "Why Choose Us" section by heading text
  function findFeaturesSection() {
    // Try to find a heading that matches the link text (most reliable)
    const linkText = (featuresLink?.textContent || "").trim().toLowerCase(); // "why choose us"
    const headings = Array.from(document.querySelectorAll("h1,h2,h3"));

    // Find heading that includes "why choose us" (or similar)
    let h = headings.find(x => (x.textContent || "").trim().toLowerCase().includes(linkText));

    // Fallback keywords if your heading isn't exactly the same text
    if (!h) {
      const keywords = ["why choose us", "why choose", "choose us", "features"];
      h = headings.find(x => keywords.some(k => (x.textContent || "").trim().toLowerCase().includes(k)));
    }

    if (!h) return null;

    // Scroll to the closest section/container around that heading
    return h.closest("section") || h.closest("div") || h;
  }

  // 2) Force scroll (prevent hijack) — and create anchor on the correct section
  if (featuresLink) {
    featuresLink.addEventListener("click", function (e) {
      e.preventDefault();

      // If a real #features exists, use it
      let target = document.getElementById("features");

      // Otherwise locate the actual section by heading and pin an anchor there
      if (!target) {
        const section = findFeaturesSection();
        if (section) {
          // Create an anchor just before the section (or inside it)
          target = document.createElement("div");
          target.id = "features";
          target.style.position = "relative";
          section.parentNode.insertBefore(target, section);
        }
      }

      // Finally scroll
      if (target) {
        target.scrollIntoView({ behavior: "smooth" });
      }
    });
  }
});
</script>


<!-- Floating contact button JS -->
  <script>
    function toggleSocials() {
      const socials = document.getElementById('socialButtons');
      socials.classList.toggle('active');
    }
  </script>
</body>

<!-- SCRIPTS -->
<script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
<script>
  AOS.init({
    once: false,        // re-animate every time
    mirror: true,       // animate again when scrolling up
    duration: 800,
    easing: 'ease-out-cubic'
  });

  function toggleSocials() {
    const socials = document.getElementById("socialButtons");
    socials.classList.toggle("show");
  }

  const slides = document.querySelectorAll('.banner-slide');
  const dots = document.querySelectorAll('.dot');
  let currentSlide = 0;

  function showSlide(index) {
    slides.forEach((slide, i) => {
      slide.classList.toggle('active', i === index);
      dots[i].classList.toggle('active', i === index);
    });
    currentSlide = index;
  }

  dots.forEach(dot => {
    dot.addEventListener('click', () => {
      const index = parseInt(dot.getAttribute('data-slide'));
      showSlide(index);
    });
  });

  setInterval(() => {
    let next = (currentSlide + 1) % slides.length;
    showSlide(next);
  }, 5000);

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".video-fav-btn").forEach(btn => {
      btn.addEventListener("click", () => {
        btn.classList.toggle("active");
        btn.textContent = btn.classList.contains("active") ? "❤️" : "♡";
      });
    });
  });
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const footer = document.querySelector("footer#contact");
  if (!footer) return;

  // enable animation mode only when JS is running
  document.documentElement.classList.add("footer-anim-ready");

  const obs = new IntersectionObserver((entries) => {
    if (entries.some(e => e.isIntersecting)) {
      footer.classList.add("footer-reveal");
      obs.disconnect(); // ✅ reveal only once
    }
  }, { threshold: 0.15 });

  obs.observe(footer);
});
</script>

<script>
  window.addEventListener('scroll', function () {
    AOS.refresh();
  });
</script>

<script>
  //carousel
  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".carousel-wrapper").forEach(wrapper => {
      const track = wrapper.querySelector(".carousel-track");
      const slides = Array.from(track.children);
      const dotsNav = wrapper.querySelector(".carousel-dots");
      const container = wrapper.querySelector(".carousel-container");

      let currentIndex = 0;
      let slidesPerPage = 1;
      let totalPages = 1;

      let startX = 0;
      let isDragging = false;

      const getSlidesPerPage = () => {
        const containerWidth = container.offsetWidth;
        let totalWidth = 0;
        let count = 0;

        for (let slide of slides) {
          const style = getComputedStyle(slide);
          const margin = parseFloat(style.marginLeft) + parseFloat(style.marginRight);
          const slideWidth = slide.offsetWidth + margin;

          if (totalWidth + slideWidth <= containerWidth) {
            totalWidth += slideWidth;
            count++;
          } else break;
        }
        return Math.max(count, 1);
      };

      const buildDots = () => {
        dotsNav.innerHTML = "";
        if (totalPages <= 1) return;
        for (let i = 0; i < totalPages; i++) {
          const dot = document.createElement("button");
          if (i === currentIndex) dot.classList.add("active");
          dotsNav.appendChild(dot);
          dot.addEventListener("click", () => goToSlide(i));
        }
      };

      const updateSlidePosition = () => {
        const slideWidth = slides[0].offsetWidth +
          (parseFloat(getComputedStyle(slides[0]).marginLeft) + parseFloat(getComputedStyle(slides[0]).marginRight));

        // normal start index
        let start = currentIndex * slidesPerPage;

        // if on last page and slide count < max slides allowed, shift start backwards so page is filled
        if (currentIndex === totalPages - 1 && slides.length % slidesPerPage !== 0) {
          start = slides.length - slidesPerPage;
        }

        const shift = start * slideWidth;
        track.style.transform = `translateX(-${shift}px)`;

        // Update dots
        dotsNav.querySelectorAll("button").forEach((dot, i) => {
          dot.classList.toggle("active", i === currentIndex);
        });
      };

      const goToSlide = (index) => {
        currentIndex = Math.max(0, Math.min(index, totalPages - 1));
        updateSlidePosition();
      };

      const recalc = () => {
        slidesPerPage = getSlidesPerPage();
        totalPages = Math.ceil(slides.length / slidesPerPage);
        buildDots();
        goToSlide(currentIndex); // keep current index if possible
      };

      // --- Touch/Swipe Handlers ---
      // --- Touch/Swipe Handlers ---
      container.addEventListener("touchstart", (e) => {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        isDragging = true;
      });

      container.addEventListener("touchend", (e) => {
        if (!isDragging) return;
        const endX = e.changedTouches[0].clientX;
        const endY = e.changedTouches[0].clientY;

        const diffX = endX - startX;
        const diffY = endY - startY;

        // Only treat as swipe if horizontal movement is bigger than vertical
        if (Math.abs(diffX) > 30 && Math.abs(diffX) > Math.abs(diffY)) {
          if (diffX < 0) goToSlide(currentIndex + 1);
          else goToSlide(currentIndex - 1);
        }

        isDragging = false;
      });

      window.addEventListener("resize", recalc);

      const images = wrapper.querySelectorAll("img");
      let loaded = 0;
      if (images.length) {
        images.forEach(img => {
          if (img.complete) {
            loaded++;
            if (loaded === images.length) recalc();
          } else {
            img.addEventListener("load", () => {
              loaded++;
              if (loaded === images.length) recalc();
            });
          }
        });
      } else {
        recalc(); // no images
      }
    });
  });
</script>

<!--stop vids from autoplaying on soft reload-->
<script>
  document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".video-thumb iframe").forEach(iframe => {
      const src = iframe.src;
      iframe.src = "";
      iframe.src = src;
    });
  });
</script>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    const track = document.querySelector(".blog-track");
    const cards = document.querySelectorAll(".blog-card");
    const prev = document.querySelector(".blog-slider-btn.prev");
    const next = document.querySelector(".blog-slider-btn.next");
    let index = 0;

    function updateSlider() {
      const slidesPerView = window.innerWidth <= 768 ? 1 : 3;
      const cardWidth = cards[0].offsetWidth + 20;
      const totalCards = cards.length;

      // Hide slider buttons if not enough cards
      if (totalCards <= slidesPerView) {
        prev.style.display = "none";
        next.style.display = "none";
        track.style.transform = "translateX(0)";
        return;
      } else {
        prev.style.display = "flex";
        next.style.display = "flex";
      }

      // Infinite looping
      index = (index + totalCards) % totalCards;
      track.style.transition = "transform 0.5s ease";
      track.style.transform = `translateX(-${index * cardWidth}px)`;
    }

    prev.addEventListener("click", () => {
      index = (index - 1 + cards.length) % cards.length;
      updateSlider();
    });

    next.addEventListener("click", () => {
      index = (index + 1) % cards.length;
      updateSlider();
    });

    window.addEventListener("resize", updateSlider);
    updateSlider();
  });
</script>
