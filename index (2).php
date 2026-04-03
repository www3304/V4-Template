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
/* --- EDITORIAL B2B THEME VARIABLES --- */
:root {
  --bg-main: #0a0a0a;         /* 深石墨灰背景 */
  --bg-surface: #141414;      /* 稍浅的深色区块 */
  --text-main: #ffffff;
  --text-muted: #888888;
  --accent: #C5A880;          /* 拉丝金（强调色） */
  --border-light: rgba(255, 255, 255, 0.1);
  --radius-sharp: 0px;        /* 锐利边缘 */
}

*, *::before, *::after {
  box-sizing: border-box;
}

html {
  scroll-behavior: smooth;     /* 开启原生平滑滚动 */
  width: 100%;
  max-width: 100vw;
  overflow-x: hidden !important; 
}

body {
  font-family: 'Inter', sans-serif;
  background-color: var(--bg-main);
  color: var(--text-main);
  margin: 0;
  line-height: 1.6;
  width: 100%;
  max-width: 100vw;
  overflow-x: hidden !important; 
  position: relative;          /* 防止内部 absolute 元素越界 */
}

img, video, iframe {
  max-width: 100%;
  height: auto;
}

/* 预留出顶部导航栏的高度，防止跳转后标题被导航栏遮挡 */
section, footer {
  scroll-margin-top: 90px; 
}

h1, h2, h3, h4 {
  font-weight: 500; /* 高级感通常不需太粗，依赖字号拉开差距 */
  letter-spacing: -0.03em;
  margin-top: 0;
}

/* --- 1. 沉浸式极简导航 --- */
.editorial-nav {
  position: fixed;
  top: 0; left: 0; right: 0;
  padding: 20px 40px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 99999;
  transition: background 0.4s ease, border-bottom 0.4s ease;
  border-bottom: 1px solid transparent;
}
.editorial-nav.scrolled {
  background: rgba(10, 10, 10, 0.85);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border-light);
}
.nav-logo img { height: 40px; object-fit: contain; }
.nav-links { display: flex; gap: 40px; align-items: center; }
.nav-links a {
  color: var(--text-main);
  text-decoration: none;
  font-size: 13px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  opacity: 0.7;
  transition: opacity 0.3s;
}
.nav-links a:hover { opacity: 1; color: var(--accent); }

/* --- 2. 全屏巨幅 HERO --- */
.hero-editorial {
  position: relative;
  width: 100%; height: 100vh;
  display: flex;
  align-items: flex-end; /* 内容靠下对齐，留出顶部空间 */
  padding: 80px 60px;
}
.hero-bg {
  position: absolute; top: 0; left: 0;
  width: 100%; height: 100%;
  object-fit: cover;
  z-index: -2;
  filter: grayscale(30%) contrast(1.1); /* 让图片稍微冷峻一些 */
}
.hero-overlay {
  position: absolute; top: 0; left: 0;
  width: 100%; height: 100%;
  background: linear-gradient(to top, rgba(10,10,10,0.95) 0%, rgba(10,10,10,0.3) 100%);
  z-index: -1;
}
.hero-content {
  max-width: 1200px;
  z-index: 1;
}
.hero-content h1 {
  font-size: clamp(3rem, 7vw, 7rem); /* 巨型排版 */
  line-height: 0.95;
  text-transform: uppercase;
  margin-bottom: 30px;
}
.hero-content p {
  font-size: clamp(16px, 1.5vw, 20px);
  color: var(--text-muted);
  max-width: 500px;
}

/* --- 3. 不对称布局的关于我们 --- */
.editorial-section { padding: 140px 60px; max-width: 1400px; margin: 0 auto; }
.asym-grid {
  display: grid;
  grid-template-columns: 4fr 6fr; /* 左侧留白/标题，右侧内容/图片 */
  gap: 80px;
  align-items: flex-start;
}
.section-meta {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.section-num {
  font-size: 14px;
  color: var(--accent);
  letter-spacing: 0.1em;
}
.section-meta h2 {
  font-size: clamp(2.5rem, 4vw, 4.5rem);
  line-height: 1.1;
}
.asym-content {
  font-size: 18px;
  color: var(--text-muted);
}
.brutalist-image {
  margin-top: 60px;
  width: 100%;
  height: 600px;
  object-fit: cover;
  filter: grayscale(100%); /* B2B 高级感处理 */
  transition: filter 0.5s;
}
.brutalist-image:hover { filter: grayscale(0%); }

/* --- 4. 极简线条列表 (替代原本的 Bento Card) --- */
.line-list {
  display: flex;
  flex-direction: column;
  margin-top: 60px;
  border-top: 1px solid var(--border-light);
}
.line-item {
  display: grid;
  grid-template-columns: 80px 1fr 2fr; /* 图标 - 标题 - 描述 */
  gap: 40px;
  padding: 40px 0;
  border-bottom: 1px solid var(--border-light);
  align-items: start;
  transition: background 0.3s;
}
.line-item:hover { background: var(--bg-surface); padding-left: 20px; }
.line-item h4 { font-size: 24px; margin: 0; }
.line-item p { margin: 0; color: var(--text-muted); font-size: 16px; }
.line-icon { width: 40px; height: 40px; filter: invert(1); opacity: 0.8; } /* 假设原图标是深色，这里反转为白色 */

@media (max-width: 992px) {
  .asym-grid, .line-item { grid-template-columns: 1fr; }
  .editorial-nav { padding: 20px; }
  .hero-editorial { padding: 40px 20px; }
  .editorial-section { padding: 80px 20px; }
}

/* --- 5. 建筑级服务网格 (Services) --- */
.meta-desc {
  color: var(--text-muted);
  font-size: 18px;
  max-width: 600px;
  margin-top: 20px;
}
.service-grid-sharp {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  margin-top: 60px;
  border-top: 1px solid var(--border-light);
  border-left: 1px solid var(--border-light);
}
.service-cell {
  padding: 50px 40px;
  border-right: 1px solid var(--border-light);
  border-bottom: 1px solid var(--border-light);
  background: var(--bg-main);
  transition: background 0.4s ease;
  display: flex;
  flex-direction: column;
}
.service-cell:hover {
  background: var(--bg-surface);
}
.service-img {
  width: 100%;
  height: 200px;
  object-fit: cover;
  filter: grayscale(100%); /* 保持冷峻感 */
  margin-bottom: 30px;
  transition: filter 0.4s ease;
}
.service-cell:hover .service-img {
  filter: grayscale(0%);
}
.service-icon-minimal {
  font-size: 32px;
  color: var(--accent);
  margin-bottom: 30px;
}
.service-cell h4 {
  font-size: 22px;
  margin-bottom: 15px;
}
.service-cell p {
  color: var(--text-muted);
  font-size: 15px;
  margin: 0;
}

/* --- 6. 极简视频展示区 (Video) --- */
.video-section {
  display: flex;
  flex-direction: column;
  gap: 40px;
}
.video-editorial-wrapper {
  position: relative;
  width: 100%;
  padding-bottom: 56.25%; /* 16:9 Aspect Ratio */
  height: 0;
  background: var(--bg-surface);
  border: 1px solid var(--border-light);
}
.video-editorial-wrapper iframe, 
.video-editorial-wrapper video {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  border: none;
  filter: grayscale(60%);
  transition: filter 0.5s ease;
}
.video-editorial-wrapper:hover iframe, 
.video-editorial-wrapper:hover video {
  filter: grayscale(0%);
}

/* --- 7. 高级社论级 Footer (Contact) --- */
.editorial-footer {
  border-top: 1px solid var(--border-light);
  padding: 100px 60px 40px;
  max-width: 1400px;
  margin: 0 auto;
}
.footer-huge-text {
  font-size: clamp(4rem, 8vw, 9rem);
  font-weight: 700;
  line-height: 0.9;
  letter-spacing: -0.04em;
  text-transform: uppercase;
  margin-bottom: 80px;
  color: var(--text-main);
}
.footer-grid-editorial {
  display: grid;
  grid-template-columns: 4fr 6fr;
  gap: 80px;
}
.footer-info-block {
  margin-bottom: 30px;
}
.footer-info-block strong {
  display: block;
  font-size: 13px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--text-muted);
  margin-bottom: 10px;
}
.footer-info-block a, .footer-info-block span {
  font-size: 20px;
  color: var(--text-main);
  text-decoration: none;
  transition: color 0.3s;
}
.footer-info-block a:hover {
  color: var(--accent);
}

/* 极简表单设计：去边框，仅保留下划线 */
.editorial-form {
  display: flex;
  flex-direction: column;
  gap: 40px;
}
.editorial-form input, .editorial-form textarea {
  width: 100%;
  background: transparent;
  border: none;
  border-bottom: 1px solid var(--border-light);
  color: var(--text-main);
  font-size: 18px;
  padding: 10px 0;
  font-family: 'Inter', sans-serif;
  border-radius: 0;
  outline: none;
  transition: border-color 0.3s;
}
.editorial-form input::placeholder, .editorial-form textarea::placeholder {
  color: rgba(255, 255, 255, 0.3);
}
.editorial-form input:focus, .editorial-form textarea:focus {
  border-bottom: 1px solid var(--accent);
}
.editorial-btn {
  align-self: flex-start;
  background: var(--text-main);
  color: var(--bg-main);
  border: none;
  padding: 16px 40px;
  font-size: 14px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  cursor: pointer;
  transition: background 0.3s, color 0.3s;
}
.editorial-btn:hover {
  background: var(--accent);
  color: #fff;
}

@media (max-width: 992px) {
  .service-grid-sharp, .footer-grid-editorial { grid-template-columns: 1fr; }
  .editorial-footer { padding: 60px 20px 40px; }
  .footer-huge-text { margin-bottom: 40px; }
}

/* --- 8. 移动端侧滑菜单 (Mobile Slide-out Menu) --- */

/* 桌面端隐藏按钮和遮罩 */
.menu-toggle-btn { display: none; }
.menu-overlay { display: none; }

@media (max-width: 992px) {
  /* 显示汉堡按钮，并保证它的层级最高 */
  .menu-toggle-btn {
    display: block;
    background: transparent;
    border: none;
    cursor: pointer;
    z-index: 100000; 
  }

  /* 导航链接变身为侧滑抽屉 */
  .nav-links {
    position: fixed;
    top: 0;
    right: 0;
    width: 280px;         /* 抽屉宽度 */
    height: 100vh;
    background: var(--bg-surface);
    border-left: 1px solid var(--border-light);
    flex-direction: column;
    justify-content: center;
    align-items: flex-start;
    padding: 80px 40px;
    gap: 30px;
    
    /* 默认隐藏在屏幕右侧外 */
    transform: translateX(100%); 
    transition: transform 0.4s cubic-bezier(0.77, 0.2, 0.05, 1.0); /* 高级丝滑曲线 */
    z-index: 99999;
  }

  /* 触发滑出效果的类 */
  .nav-links.nav-open {
    transform: translateX(0);
  }

  /* 手机端放大字体 */
  .nav-links a {
    font-size: 18px; 
    opacity: 1; 
  }

  /* 背景遮罩层 */
  .menu-overlay {
    display: block;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 99998;
    
    /* 默认透明且不可点击 */
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.4s ease;
  }

  .menu-overlay.active {
    opacity: 1;
    pointer-events: auto;
  }
  
  /* 修复手机端语言切换下拉框样式 */
  .lang-dropdown-content {
    position: static;
    background: transparent;
    box-shadow: none;
    border: 1px solid var(--border-light);
    margin-top: 15px;
  }
  .lang-dropdown-content a { color: var(--text-main); }
}

.brutalist-image,
.service-img,
.video-editorial-wrapper iframe,
.video-editorial-wrapper video {
  filter: grayscale(0%) !important; 
}

/* 彻底摒弃格子，改为横向大行块 */
.service-list-editorial {
  display: flex;
  flex-direction: column;
  margin-top: 60px;
}

.service-row {
  display: grid;
  grid-template-columns: 1fr 1fr; /* 左图右文 */
  gap: 60px;
  align-items: center;
  padding: 80px 0;
  border-bottom: 1px solid var(--border-light);
}

/* 偶数行反转排列 (左文右图) */
.service-row:nth-child(even) {
  grid-template-columns: 1fr 1fr;
  direction: rtl; /* 巧妙利用 RTL 反转布局 */
}
.service-row:nth-child(even) > * {
  direction: ltr; /* 内容文字恢复正常阅读顺序 */
}

.service-row-img {
  width: 100%;
  height: 400px;
  object-fit: cover;
}

.service-row-content {
  max-width: 500px;
}

.service-row-content h4 {
  font-size: 32px;
  margin-bottom: 20px;
  color: var(--text-main);
}

.service-row-content p {
  color: var(--text-muted);
  font-size: 16px;
  line-height: 1.8;
}

@media (max-width: 992px) {
  .service-row, .service-row:nth-child(even) {
    grid-template-columns: 1fr;
    direction: ltr;
    padding: 40px 0;
    gap: 30px;
  }
  .service-row-img { height: 250px; }
}

/* =========================================================
   THE FINAL 1% POLISH (质感跃升补丁)
   ========================================================= */

/* 1. 智能平衡大标题，防止单字换行破坏美感 */
h1, h2, h3, .footer-huge-text {
  text-wrap: balance; 
}

/* 2. 重塑 WhatsApp 按钮：融入暗黑高级主题 */
.whatsapp-float {
  position: fixed;
  bottom: 30px;
  right: 30px;
  width: 60px;
  height: 60px;
  /* 默认改为深色玻璃态，而非刺眼的纯绿 */
  background-color: rgba(20, 20, 20, 0.85); 
  backdrop-filter: blur(10px);
  border: 1px solid var(--border-light);
  border-radius: 50%;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  /* 取消廉价的无限呼吸灯动画，改为静态高级感 */
  animation: none; 
}
.whatsapp-float img {
  width: 28px;
  height: 28px;
  filter: brightness(0) invert(1); /* 保持纯白 Icon */
  transition: transform 0.4s ease;
}
.whatsapp-float:hover {
  /* 只有 Hover 时才展示品牌绿，并轻微上浮 */
  background-color: #25d366;
  border-color: #25d366;
  transform: translateY(-5px) scale(1.05);
  box-shadow: 0 15px 35px rgba(37, 211, 102, 0.3);
}
.whatsapp-float:hover img {
  transform: scale(1.1);
}

/* 3. 高级表单按钮：边框填充微交互 */
.editorial-btn {
  position: relative;
  overflow: hidden;
  background: transparent;
  color: var(--text-main);
  border: 1px solid var(--border-light);
  z-index: 1;
}
.editorial-btn::before {
  content: '';
  position: absolute;
  top: 0; left: 0;
  width: 0%;
  height: 100%;
  background: var(--text-main);
  z-index: -1;
  transition: width 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.editorial-btn:hover {
  color: var(--bg-main); /* Hover 时文字变暗 */
  background: transparent;
  border-color: var(--text-main);
}
.editorial-btn:hover::before {
  width: 100%; /* 背景色从左向右丝滑填满 */
}

/* 4. AOS 电影级丝滑曲线覆盖 & 更慢的速度 */
[data-aos] {
  transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1) !important;
  transition-duration: 3000ms !important; /* 强制放慢到 1.8秒，原来大概是 0.8~1秒 */
}
    </style>
</head>
<body>

  <div class="menu-overlay" id="menuOverlay" onclick="toggleMobileMenu()"></div>

  <nav class="editorial-nav" id="mainNav" data-aos="fade-down" data-aos-duration="1000">
    <div class="nav-logo">
      <img src="<?= htmlspecialchars($company['logo'] ?? '') ?>" alt="<?= htmlspecialchars($company['name'] ?? 'Logo') ?>">
    </div>

    <button class="menu-toggle-btn" onclick="toggleMobileMenu()" aria-label="Toggle Menu">
      <svg viewBox="0 0 24 24" width="28" height="28" stroke="#ffffff" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
      </svg>
    </button>

    <div class="nav-links" id="navLinks">
      <a href="#home" onclick="toggleMobileMenu()">Home</a>
      <a href="#about" onclick="toggleMobileMenu()">About</a>
      <a href="#features" onclick="toggleMobileMenu()">Why Us</a>
      <a href="#provide" onclick="toggleMobileMenu()">Services</a>
      <a href="#video" onclick="toggleMobileMenu()">Video</a>
      <a href="#contact" onclick="toggleMobileMenu()">Contact</a>
      
      <div class="lang-dropdown">
        <button class="lang-dropbtn" style="color: inherit;">
          <?= $language_id == 1 ? 'BM' : 'EN' ?> ▼
        </button>
        <div class="lang-dropdown-content">
          <a href="?lang=2" class="<?= $language_id == 2 ? 'active' : '' ?>">English (EN)</a>
          <a href="?lang=1" class="<?= $language_id == 1 ? 'active' : '' ?>">Bahasa Melayu (BM)</a>
        </div>
      </div>
    </div>
  </nav>

  <script>
    window.addEventListener('scroll', () => {
      document.getElementById('mainNav').classList.toggle('scrolled', window.scrollY > 50);
    });
  </script>

  <section id="home" class="hero-editorial">
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
      <p>Consultation, Trade & Services designed for the modern era.</p>
    </div>
  </section>

  <section id="about" class="editorial-section">
    <div class="asym-grid">
      <div class="section-meta" data-aos="fade-right">
        <span class="section-num">01 // IDENTITY</span>
        <h2><?= $company['about_title'] ?? 'About Us' ?></h2>
      </div>
      
      <div class="asym-content" data-aos="fade-up">
        <div><?= html_entity_decode($company['about_description'] ?? '') ?></div>
        
        <img class="brutalist-image" src="<?= !empty($company['gallery']) ? htmlspecialchars($company['gallery'][0]['image_path']) : 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&q=80&w=800' ?>" alt="About Us">
      </div>
    </div>
  </section>

  <section id="features" class="editorial-section">
    <div class="section-meta" data-aos="fade-up">
      <span class="section-num">02 // CAPABILITIES</span>
      <h2><?= $company['features_title'] ?? 'Why Choose Us' ?></h2>
    </div>

    <div class="line-list">
      <?php if (!empty($company['features'])): ?>
        <?php foreach (array_slice($company['features'], 0, 5) as $feature): ?>
          <div class="line-item" data-aos="fade-up">
            
            <?php if (!empty($feature['icon'])): ?>
              <?php if (strpos($feature['icon'], '.') !== false || strpos($feature['icon'], '/') !== false): ?>
                <img src="<?= htmlspecialchars($feature['icon']) ?>" alt="Icon" class="line-icon">
              <?php else: ?>
                <i class="<?= htmlspecialchars($feature['icon']) ?> line-icon" style="font-size: 2rem;"></i>
              <?php endif; ?>
            <?php endif; ?>
            
            <h4><?= strip_tags(html_entity_decode($feature['title'])) ?></h4>
            <p><?= strip_tags(html_entity_decode($feature['description'] ?? '')) ?></p>
            
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section id="provide" class="editorial-section">
    <div class="section-meta" data-aos="fade-up">
      <span class="section-num">03 // SERVICES</span>
      <h2><?= $company['provide_title'] ?? 'Our Services' ?></h2>
      <div class="meta-desc"><?= html_entity_decode($company['provide_text'] ?? '') ?></div>
    </div>

    <div class="service-list-editorial">
      <?php if (!empty($company['provide'])): ?>
        <?php foreach (array_slice($company['provide'], 0, 5) as $service): ?>
          <div class="service-row" data-aos="fade-up">
            
            <?php if (!empty($service['icon']) && (strpos($service['icon'], '.') !== false || strpos($service['icon'], '/') !== false)): ?>
              <img src="<?= htmlspecialchars($service['icon']) ?>" alt="Service" class="service-row-img">
            <?php else: ?>
              <div style="height: 400px; display: flex; align-items: center; justify-content: center; background: var(--bg-surface);">
                 <i class="<?= htmlspecialchars($service['icon']) ?>" style="font-size: 60px; color: var(--accent);"></i>
              </div>
            <?php endif; ?>
            
            <div class="service-row-content">
              <h4><?= strip_tags(html_entity_decode($service['title'])) ?></h4>
              <p><?= strip_tags(html_entity_decode($service['description'] ?? '')) ?></p>
            </div>
            
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section id="video" class="editorial-section video-section">
    <div class="section-meta" data-aos="fade-right">
      <span class="section-num">04 // SHOWCASE</span>
      <h2><?= $company['video_title'] ?? 'Demo' ?></h2>
    </div>
    
    <div class="video-editorial-wrapper" data-aos="fade-up" data-aos-duration="1000">
      <?php if (!empty($company['videos'])): ?>
        <?= html_entity_decode($company['videos'][0]['iframe_code']) ?>
      <?php else: ?>
        <iframe src="https://www.youtube.com/embed/ScMzIvxBSi4?autoplay=0&controls=1" title="Demo Video" allowfullscreen></iframe>
      <?php endif; ?>
    </div>
  </section>

  <footer id="contact" class="editorial-footer">
    <div class="footer-huge-text" data-aos="fade-up">Let's Talk.</div>
    
    <div class="footer-grid-editorial">
      <div data-aos="fade-right">
        
        <div class="footer-info-block">
          <strong>Email Inquiry</strong>
          <a href="mailto:<?= htmlspecialchars($company['email'] ?? '') ?>"><?= htmlspecialchars($company['email'] ?? '') ?></a>
        </div>
        
        <div class="footer-info-block">
          <strong>Direct Line</strong>
          <a href="tel:<?= htmlspecialchars($company['phone'] ?? '') ?>"><?= htmlspecialchars($company['phone'] ?? '') ?></a>
        </div>
        
        <div class="footer-info-block">
          <strong>Headquarters</strong>
          <span><?= nl2br(htmlspecialchars($company['address'] ?? '')) ?></span>
        </div>
        
      </div>
      
      <div data-aos="fade-left">
        <form action="" method="POST" class="editorial-form">
          <input type="text" placeholder="Your Name" required>
          <input type="email" placeholder="Your Email" required>
          <textarea placeholder="How can we help you?" rows="1" required></textarea>
          <button type="submit" class="editorial-btn">Send Message</button>
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
    
    // 【强制显示测试】：如果您的后台还没配置 WhatsApp，暂时给个假链接让按钮显示出来。
    // 等后台配置好后，您可以把下面这三行删掉。
    if (empty($whatsapp_url)) {
        $whatsapp_url = 'https://wa.me/60123456789'; 
    }
  ?>

  <?php if (!empty($whatsapp_url)): ?>
    <a href="<?= htmlspecialchars($whatsapp_url) ?>" class="whatsapp-float" target="_blank" data-aos="zoom-in" data-aos-duration="1500" data-aos-delay="500">
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
  
  <script>
  // 侧滑菜单控制逻辑
  function toggleMobileMenu() {
    // 仅在移动端尺寸下生效
    if (window.innerWidth <= 992) {
      document.getElementById('navLinks').classList.toggle('nav-open');
      document.getElementById('menuOverlay').classList.toggle('active');
    }
  }

  // 导航栏滚动变色 (保留之前的)
  window.addEventListener('scroll', () => {
    document.getElementById('mainNav').classList.toggle('scrolled', window.scrollY > 50);
  });
  </script>
</body>
</html>
