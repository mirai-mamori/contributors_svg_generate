<?php
/**
 * GitHub Contributors SVG Generator
 * 
 * 这个脚本可以抓取指定GitHub用户和项目的贡献者信息
 * 并生成一个现代化UI的SVG图片，每天自动更新
 */

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 基础配置信息
$config = [
    'github_username' => 'mirai-mamori',  // GitHub用户名
    'github_repo' => 'Sakurairo',   // GitHub仓库名
    'output_svg_path' => __DIR__ . '/contributors.svg', // SVG输出路径
    'cache_file' => __DIR__ . '/contributors_cache.json', // 缓存文件
    'stats_file' => __DIR__ . '/contributors_stats.json', // 贡献者统计数据文件
    'cache_lifetime' => 86400, // 缓存生命周期（秒）- 24小时
    'max_contributors' => 50, // 最多显示的贡献者数量
    'admin_session_lifetime' => 3600, // 管理员会话生命周期（秒）- 1小时
];

// 加载敏感配置信息
$config_file = __DIR__ . '/config.php';
if (file_exists($config_file)) {
    $sensitive_config = require($config_file);
    $config = array_merge($config, $sensitive_config);
} else {
    // 如果配置文件不存在，使用默认值并记录警告
    error_log('警告: 敏感配置文件不存在，使用默认设置。为了安全，请创建 config.php 文件。');
    $config['admin_password_hash'] = ''; // 初始为空，系统会引导用户创建密码
    $config['security_salt'] = ''; // 初始为空，系统会自动生成随机盐值
    $config['github_token'] = '';
}

/**
 * 检查缓存是否有效
 */
function isCacheValid($cache_file, $lifetime) {
    if (!file_exists($cache_file)) {
        return false;
    }
    
    $file_time = filemtime($cache_file);
    return (time() - $file_time) < $lifetime;
}

/**
 * 从GitHub API获取贡献者数据
 */
function fetchContributors($username, $repo, $token = '', $max_contributors = 50) {
    $api_url = "https://api.github.com/repos/{$username}/{$repo}/contributors?per_page={$max_contributors}";
    
    // 初始化 cURL
    $ch = curl_init();
    
    // 设置 cURL 选项
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP GitHub Contributors');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/vnd.github.v3+json']);
    
    // 如果有提供GitHub Token，添加到头部
    if (!empty($token)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/vnd.github.v3+json',
            "Authorization: token {$token}"
        ]);
    }
    
    // 执行请求
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // 检查错误
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log("获取GitHub贡献者时出错: cURL错误 - " . $error);
        return [];
    }
    
    curl_close($ch);
    
    // 验证HTTP状态码
    if ($http_code !== 200) {
        error_log("获取GitHub贡献者时出错: HTTP状态码 - " . $http_code);
        return [];
    }
    
    // 解析JSON
    $contributors = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("获取GitHub贡献者时出错: JSON解析错误 - " . json_last_error_msg());
        return [];
    }
    
    if (!is_array($contributors)) {
        error_log("获取GitHub贡献者时出错: API返回的数据格式无效");
        return [];
    }
    
    return $contributors;
}

/**
 * 获取图片并转换为Base64编码
 */
function getImageAsBase64($url) {
    // 初始化cURL会话
    $ch = curl_init();
    
    // 设置cURL选项
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP GitHub Contributors');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 跳过SSL验证，不推荐用于生产环境
    
    // 执行请求，获取二进制数据
    $data = curl_exec($ch);
    
    // 检查是否发生错误
    if (curl_errno($ch)) {
        error_log('获取头像失败: ' . curl_error($ch));
        curl_close($ch);
        return $url; // 出错时返回原始URL
    }
    
    // 获取图片MIME类型
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    // 如果无法获取MIME类型，尝试从URL推断
    if (empty($contentType)) {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        switch (strtolower($extension)) {
            case 'jpg':
            case 'jpeg':
                $contentType = 'image/jpeg';
                break;
            case 'png':
                $contentType = 'image/png';
                break;
            case 'gif':
                $contentType = 'image/gif';
                break;
            default:
                $contentType = 'image/png'; // 默认使用PNG格式
        }
    }
    
    // 将二进制数据编码为Base64
    $base64 = base64_encode($data);
    
    return 'data:' . $contentType . ';base64,' . $base64;
}

/**
 * 生成SVG图片
 */
function generateSvg($contributors, $username, $repo) {
    $total_contributors = count($contributors);
    if ($total_contributors === 0) {
        return generateErrorSvg("无法获取贡献者数据");
    }
    
    // 按贡献量排序
    usort($contributors, function($a, $b) {
        return $b['contributions'] - $a['contributions'];
    });
      // 定义不同级别贡献者的头像大小
    $avatarSizes = [
        'top' => 80,      // 前5名，更大的头像
        'middle' => 60,   // 6-15名，中等大小的头像
        'regular' => 50   // 其余，较小的头像
    ];
    
    // 计算每个贡献者的大小
    foreach ($contributors as $index => &$contributor) {
        if ($index < 5) {
            $contributor['size'] = $avatarSizes['top'];
            $contributor['tier'] = 'top';
        } elseif ($index < 15) {
            $contributor['size'] = $avatarSizes['middle'];
            $contributor['tier'] = 'middle';
        } else {
            $contributor['size'] = $avatarSizes['regular'];
            $contributor['tier'] = 'regular';
        }
    }
    unset($contributor); // 解除引用
      // 计算SVG尺寸
    $gap = 25;           // 元素间的水平间距
    $verticalGap = 35;   // 元素间的垂直间距
    $padding = 40;       // SVG内边距
    $rowMaxItems = 7;    // 每行最多显示的头像数
      // 对不同级别的贡献者分开布局
    $topContributors = array_slice($contributors, 0, 5);
    $middleContributors = array_slice($contributors, 5, 10);
    $regularContributors = array_slice($contributors, 15);
      // 计算每一组贡献者所需的行数
    $topRows = ceil(count($topContributors) / $rowMaxItems);
    $middleRows = ceil(count($middleContributors) / $rowMaxItems);
    $regularRows = ceil(count($regularContributors) / $rowMaxItems);
    
    // 增加中级贡献者的垂直间距，避免重叠问题
    $middleVerticalGap = 55; // 增加活跃贡献者的垂直间距
    
    // 计算每一组贡献者的高度
    $topHeight = $topRows > 0 ? ($topRows * $avatarSizes['top'] + ($topRows - 1) * $gap) : 0;
    $middleHeight = $middleRows > 0 ? ($middleRows * $avatarSizes['middle'] + ($middleRows - 1) * $middleVerticalGap) : 0; // 使用更大的垂直间距
    $regularHeight = $regularRows > 0 ? ($regularRows * $avatarSizes['regular'] + ($regularRows - 1) * $gap) : 0;// 添加分组标题的高度
    $sectionTitleHeight = 40;     // 增加标题高度，从30px增加到40px
    $sectionSpacing = 45;         // 增加分组间距，从25px增加到45px
    
    $headerHeight = 80;           // 增加页眉高度，从70px增加到80px
    $footerHeight = 50;           // 增加页脚高度，从40px增加到50px
    
    // 计算内容高度（包括分组标题和间距）
    $contentHeight = 0;
    if ($topHeight > 0) {
        $contentHeight += $sectionTitleHeight + $topHeight;
    }
    if ($middleHeight > 0) {
        $contentHeight += $sectionSpacing + $sectionTitleHeight + $middleHeight;
    }
    if ($regularHeight > 0) {
        $contentHeight += $sectionSpacing + $sectionTitleHeight + $regularHeight;
    }
    
    // 计算SVG宽度（使用最大的一组宽度）
    $maxRowItems = $rowMaxItems;
    $largestSize = $avatarSizes['top'];
    $svgWidth = $padding * 2 + $maxRowItems * $largestSize + ($maxRowItems - 1) * $gap;
    
    // 计算总高度
    $svgHeight = $padding * 2 + $headerHeight + $contentHeight + $footerHeight;    // 开始生成SVG
    $svg = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
    $svg .= '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">';
    $svg .= '<svg width="' . $svgWidth . '" height="' . $svgHeight . '" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1">';
      // 添加样式
    $svg .= '<defs>
        <style>
            @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;display=swap");
            .background { fill: rgba(245, 245, 245, 0.5); }
            .card { fill: rgba(250, 250, 250, 0.9); stroke: rgba(200, 200, 200, 0.5); stroke-width: 1; rx: 8; }
            .header { font-family: "Inter", sans-serif; font-size: 18px; font-weight: 600; fill: #2d3748; }
            .subheader { font-family: "Inter", sans-serif; font-size: 14px; fill: #4a5568; }
            .section-title { font-family: "Inter", sans-serif; font-size: 16px; font-weight: 500; fill: #3182ce; }
            .avatar { rx: 50%; }
            .name { font-family: "Inter", sans-serif; fill: #2d3748; text-anchor: middle; }
            .name.top { font-size: 14px; font-weight: 500; }
            .name.middle { font-size: 12px; }
            .name.regular { font-size: 11px; }
            .contributions { font-family: "Inter", sans-serif; fill: #4a5568; text-anchor: middle; }
            .contributions.top { font-size: 12px; }
            .contributions.middle { font-size: 11px; }
            .contributions.regular { font-size: 10px; }
            .footer { font-family: "Inter", sans-serif; font-size: 12px; fill: #4a5568; }
            .contributor-link:hover { opacity: 0.85; }
        </style>
        
        <filter id="shadow" x="-5%" y="-5%" width="110%" height="110%">
            <feDropShadow dx="0" dy="2" stdDeviation="4" flood-opacity="0.15"/>
        </filter>
    </defs>';
    
    // 背景和卡片
    $svg .= '<rect width="' . $svgWidth . '" height="' . $svgHeight . '" class="background" />';
    $svg .= '<rect x="0" y="0" width="' . $svgWidth . '" height="' . $svgHeight . '" class="card" filter="url(#shadow)" />';
    
    // 标题
    $svg .= '<text x="' . $padding . '" y="' . ($padding + 25) . '" class="header">' . htmlspecialchars($repo) . ' 贡献者</text>';
    $svg .= '<text x="' . $padding . '" y="' . ($padding + 48) . '" class="subheader">共计 ' . $total_contributors . ' 位贡献者</text>';
    
    // 当前Y位置，用于跟踪绘制位置
    $currentY = $padding + $headerHeight;
    
    // 绘制顶级贡献者（前5名）
    if (count($topContributors) > 0) {
        $svg .= '<text x="' . $padding . '" y="' . ($currentY + 20) . '" class="section-title">核心贡献者</text>';
        $currentY += $sectionTitleHeight;
        
        // 绘制顶级贡献者
        foreach ($topContributors as $index => $contributor) {
            $row = floor($index / $rowMaxItems);
            $col = $index % $rowMaxItems;
            
            $avatarSize = $contributor['size'];
            $textOffset = $avatarSize > 50 ? 20 : 18; // 调整大头像的文本位置
            
            $x = $padding + $col * ($largestSize + $gap) + ($largestSize - $avatarSize) / 2;
            $y = $currentY + $row * ($avatarSize + $gap);
            
            $login = $contributor['login'];
            $avatar_url = $contributor['avatar_url'];
            $contributions = $contributor['contributions'];
            
            // 创建贡献者组
            $svg .= '<a xlink:href="https://github.com/' . $login . '" target="_blank" class="contributor-link">';
            $svg .= '<g>';
            
            // 头像
            $svg .= '<image x="' . $x . '" y="' . $y . '" width="' . $avatarSize . '" height="' . $avatarSize . '" xlink:href="' . getImageAsBase64($avatar_url) . '" class="avatar" />';
            
            // 名称
            $svg .= '<text x="' . ($x + $avatarSize / 2) . '" y="' . ($y + $avatarSize + $textOffset) . '" class="name top">' . $login . '</text>';
            
            // 贡献数
            $svg .= '<text x="' . ($x + $avatarSize / 2) . '" y="' . ($y + $avatarSize + $textOffset + 15) . '" class="contributions top">' . $contributions . ' 贡献</text>';
            
            $svg .= '</g>';
            $svg .= '</a>';
        }
        
        // 更新Y位置到顶级贡献者部分的底部
        $currentY += $topHeight + $sectionSpacing;
    }
    
    // 绘制中级贡献者（6-15名）
    if (count($middleContributors) > 0) {
        $svg .= '<text x="' . $padding . '" y="' . ($currentY + 20) . '" class="section-title">活跃贡献者</text>';
        $currentY += $sectionTitleHeight;
          // 绘制中级贡献者
        foreach ($middleContributors as $index => $contributor) {
            $row = floor($index / $rowMaxItems);
            $col = $index % $rowMaxItems;
            
            $avatarSize = $contributor['size'];
            // 计算当前行垂直位置时考虑之前行的额外间距
            $y = $currentY + $row * ($avatarSize + $middleVerticalGap);
            $x = $padding + $col * ($largestSize + $gap) + ($largestSize - $avatarSize) / 2;
            
            $login = $contributor['login'];
            $avatar_url = $contributor['avatar_url'];
            $contributions = $contributor['contributions'];
            
            // 创建贡献者组
            $svg .= '<a xlink:href="https://github.com/' . $login . '" target="_blank" class="contributor-link">';
            $svg .= '<g>';
            
            // 头像
            $svg .= '<image x="' . $x . '" y="' . $y . '" width="' . $avatarSize . '" height="' . $avatarSize . '" xlink:href="' . getImageAsBase64($avatar_url) . '" class="avatar" />';              // 名称
            $svg .= '<text x="' . ($x + $avatarSize / 2) . '" y="' . ($y + $avatarSize + 18) . '" class="name middle">' . $login . '</text>';
              // 贡献数 - 进一步增加垂直间距避免重叠
            $svg .= '<text x="' . ($x + $avatarSize / 2) . '" y="' . ($y + $avatarSize + 18 + 14) . '" class="contributions middle">' . $contributions . ' 贡献</text>';
            
            $svg .= '</g>';
            $svg .= '</a>';
        }
        
        // 更新Y位置到中级贡献者部分的底部
        $currentY += $middleHeight + $sectionSpacing;
    }
    
    // 绘制普通贡献者（第16名以后）
    if (count($regularContributors) > 0) {
        $svg .= '<text x="' . $padding . '" y="' . ($currentY + 20) . '" class="section-title">其他贡献者</text>';
        $currentY += $sectionTitleHeight;
        
        // 绘制普通贡献者
        foreach ($regularContributors as $index => $contributor) {
            $row = floor($index / $rowMaxItems);
            $col = $index % $rowMaxItems;
            
            $avatarSize = $contributor['size'];
            $x = $padding + $col * ($largestSize + $gap) + ($largestSize - $avatarSize) / 2;
            $y = $currentY + $row * ($avatarSize + $gap);
            
            $login = $contributor['login'];
            $avatar_url = $contributor['avatar_url'];
            $contributions = $contributor['contributions'];
            
            // 创建贡献者组
            $svg .= '<a xlink:href="https://github.com/' . $login . '" target="_blank" class="contributor-link">';
            $svg .= '<g>';
            
            // 头像
            $svg .= '<image x="' . $x . '" y="' . $y . '" width="' . $avatarSize . '" height="' . $avatarSize . '" xlink:href="' . getImageAsBase64($avatar_url) . '" class="avatar" />';
            
            // 名称
            $svg .= '<text x="' . ($x + $avatarSize / 2) . '" y="' . ($y + $avatarSize + 12) . '" class="name regular">' . $login . '</text>';
            
            // 贡献数
            $svg .= '<text x="' . ($x + $avatarSize / 2) . '" y="' . ($y + $avatarSize + 22) . '" class="contributions regular">' . $contributions . '</text>';
            
            $svg .= '</g>';
            $svg .= '</a>';
        }
    }
    
    // 底部
    $date = date('Y-m-d H:i:s');
    $svg .= '<text x="' . ($svgWidth / 2) . '" y="' . ($svgHeight - $padding) . '" text-anchor="middle" class="footer">更新于: ' . $date . '</text>';
    
    // 结束SVG
    $svg .= '</svg>';
    
    return $svg;
}

/**
 * 生成错误SVG
 */
function generateErrorSvg($errorMessage) {
    $svgWidth = 400;
    $svgHeight = 100;
    
    $svg = '<?xml version="1.0" encoding="UTF-8"?>';
    $svg .= '<svg width="' . $svgWidth . '" height="' . $svgHeight . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect width="' . $svgWidth . '" height="' . $svgHeight . '" fill="#f0f0f0" rx="6" />';
    $svg .= '<text x="' . ($svgWidth / 2) . '" y="' . ($svgHeight / 2) . '" font-family="Arial" font-size="14" text-anchor="middle" fill="#666">' . $errorMessage . '</text>';
    $svg .= '</svg>';
    
    return $svg;
}

/**
 * 加载统计数据文件
 */
function loadStatsFile($stats_file) {
    // 默认数据结构
    $default_stats = [
        'manual_contributors' => [],
        'extra_stats' => []
    ];
    
    // 如果文件不存在，创建它
    if (!file_exists($stats_file)) {
        // 确保目录存在
        $dir = dirname($stats_file);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log('无法创建目录：' . $dir);
            }
        }
        
        // 写入默认结构
        if (file_put_contents($stats_file, json_encode($default_stats, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            error_log('无法创建统计数据文件：' . $stats_file);
        }
        return $default_stats;
    }
    
    // 文件存在，尝试读取
    $stats_content = file_get_contents($stats_file);
    if ($stats_content === false) {
        error_log('无法读取统计数据文件：' . $stats_file);
        return $default_stats;
    }
    
    // 尝试解析JSON
    $stats = json_decode($stats_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON解析错误：' . json_last_error_msg());
        return $default_stats;
    }
    
    // 确保数据结构完整
    if (!is_array($stats)) {
        return $default_stats;
    }
    
    if (!isset($stats['manual_contributors'])) {
        $stats['manual_contributors'] = [];
    }
    
    if (!isset($stats['extra_stats'])) {
        $stats['extra_stats'] = [];
    }
    
    return $stats;
}

/**
 * 合并GitHub贡献者和手动添加的贡献者
 */
function mergeContributors($github_contributors, $manual_contributors) {
    $all_contributors = $github_contributors;
    
    // 检查手动添加的贡献者是否已存在，不存在则添加
    foreach ($manual_contributors as $manual) {
        $exists = false;
        foreach ($all_contributors as $contributor) {
            if (isset($manual['login']) && isset($contributor['login']) && 
                $manual['login'] === $contributor['login']) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists && isset($manual['login'])) {
            $all_contributors[] = $manual;
        }
    }
    
    return $all_contributors;
}

/**
 * 确保所有必要的目录都存在
 */
function ensureDirectoriesExist($directories) {
    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("无法创建目录: " . $dir);
            } else {
                error_log("已创建目录: " . $dir);
            }
        }
    }
}

/**
 * 主执行函数
 */
function main($config) {
    // 确保文件目录存在
    ensureDirectoriesExist([
        dirname($config['cache_file']),
        dirname($config['stats_file']),
        dirname($config['output_svg_path'])
    ]);

    // 加载统计数据文件
    $stats = loadStatsFile($config['stats_file']);
    $manual_contributors = $stats['manual_contributors'] ?? [];
    
    // 检查是否需要更新缓存
    if (!isCacheValid($config['cache_file'], $config['cache_lifetime'])) {
        // 获取GitHub贡献者数据
        $github_contributors = fetchContributors(
            $config['github_username'],
            $config['github_repo'],
            $config['github_token'],
            $config['max_contributors']
        );
        
        // 合并GitHub贡献者和手动添加的贡献者
        $all_contributors = mergeContributors($github_contributors, $manual_contributors);
          // 缓存数据
        if (!empty($all_contributors)) {
            $cache_data = json_encode([
                'timestamp' => time(),
                'data' => $all_contributors
            ]);
            
            if ($cache_data === false) {
                error_log("缓存数据JSON编码失败: " . json_last_error_msg());
            } else {
                $result = file_put_contents(
                    $config['cache_file'],
                    $cache_data,
                    LOCK_EX
                );
                
                if ($result === false) {
                    error_log("写入缓存文件失败: " . $config['cache_file']);
                }
            }
        }
    } else {
        // 使用缓存
        $cache = json_decode(file_get_contents($config['cache_file']), true);
        $all_contributors = $cache['data'];
    }
      // 生成SVG
    $svg = generateSvg($all_contributors, $config['github_username'], $config['github_repo']);
    
    // 保存SVG文件
    $result = file_put_contents($config['output_svg_path'], $svg, LOCK_EX);
    if ($result === false) {
        error_log("写入SVG文件失败: " . $config['output_svg_path']);
    }
    
    return [
        'success' => true,
        'message' => 'SVG已更新',
        'time' => date('Y-m-d H:i:s'),
        'contributor_count' => count($all_contributors),
        'extra_stats' => $stats['extra_stats'] ?? []
    ];
}

/**
 * 检查用户是否已登录
 */
function isLoggedIn($config) {
    // 启动会话
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // 检查是否已登录及会话是否过期
    if (isset($_SESSION['admin_logged_in']) && 
        $_SESSION['admin_logged_in'] === true && 
        isset($_SESSION['login_time'])) {
        
        // 检查会话是否过期
        $session_age = time() - $_SESSION['login_time'];
        if ($session_age < $config['admin_session_lifetime']) {
            // 更新登录时间
            $_SESSION['login_time'] = time();
            return true;
        }
    }
    
    return false;
}

// 当通过CLI运行时
if (PHP_SAPI === 'cli') {
    $result = main($config);
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// 设置安全的会话配置
ini_set('session.cookie_httponly', 1); // 防止JavaScript访问会话cookie
ini_set('session.use_only_cookies', 1); // 仅使用cookies存储会话ID
// 如果网站支持HTTPS，取消下面这行的注释
// ini_set('session.cookie_secure', 1); // 仅通过HTTPS发送会话cookie

// 启动会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 生成CSRF令牌
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// 验证CSRF令牌
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}

// 设置正确的内容类型头以解决乱码问题
header('Content-Type: text/html; charset=UTF-8');

// 当通过Web访问时
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'setup_password':
            // 初始密码设置功能
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // 验证CSRF令牌
                if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('安全验证失败，请重试'));
                    exit;
                }
                
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                // 验证密码
                if (empty($new_password)) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('密码不能为空'));
                    exit;
                }
                
                if ($new_password !== $confirm_password) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('两次输入的密码不匹配，请重试'));
                    exit;
                }
                  // 生成新密码哈希
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // 生成随机盐值 - 32字节(64个十六进制字符)的随机字符串
                $security_salt = bin2hex(random_bytes(32));
                
                // 始终创建新的配置文件，不使用正则表达式替换
                $config_content = <<<EOT
<?php
/**
 * 敏感配置信息
 * 
 * 此文件包含敏感信息，不应该被提交到版本控制系统中
 * 请将此文件添加到 .gitignore
 */

// 安全配置
return [
    // 管理员密码的哈希值 (使用 password_hash 函数生成)
    'admin_password_hash' => '{$new_hash}', // 管理员密码哈希
    
    // 用于额外安全的随机盐值 (32字节随机字符串)
    'security_salt' => '{$security_salt}',
    
    // GitHub API访问令牌 (如有)
    'github_token' => '',
];
?>
EOT;
                
                // 写入配置文件
                $config_file = __DIR__ . '/config.php';
                $result = file_put_contents($config_file, $config_content, LOCK_EX);
                $result = file_put_contents($config_file, $config_content, LOCK_EX);
                if ($result === false) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('无法写入配置文件，密码未设置'));
                    exit;
                }
                
                // 更新当前会话中的配置
                $config['admin_password_hash'] = $new_hash;
                $config['security_salt'] = $security_salt;
                
                // 自动登录
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['login_time'] = time();
                
                header('Location: ' . $_SERVER['PHP_SELF'] . '?message=' . urlencode('初始密码设置成功！您已自动登录'));
                exit;
            }
            break;
            
        case 'login':
            // 处理登录请求
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // 验证CSRF令牌
                if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('安全验证失败，请重试'));
                    exit;
                }                // 密码安全验证（使用PHP内置的password_verify函数）
                $input_password = $_POST['password'] ?? '';
                
                // 添加调试信息
                error_log('登录尝试: ' . $input_password); 
                error_log('存储的哈希值: ' . ($config['admin_password_hash'] ?: '(空)'));
                
                // 使用password_verify进行安全的密码验证
                if (!empty($config['admin_password_hash']) && password_verify($input_password, $config['admin_password_hash'])) {
                    // 密码正确，设置登录状态
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?message=' . urlencode('登录成功'));
                } else {
                    // 密码错误或哈希值为空
                    if (empty($config['admin_password_hash'])) {
                        // 如果哈希值为空，则重定向到初始密码设置页面
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('管理员密码尚未设置或未正确保存，请先设置密码'));
                    } else {
                        // 密码错误
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('密码错误，请重试'));
                    }
                }
                exit;
            }
            break;
            
        case 'change_password':
            // 更改密码功能 - 需要登录
            if (!isLoggedIn($config)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('请先登录后再进行此操作'));
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // 验证CSRF令牌
                if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('安全验证失败，请重试'));
                    exit;
                }
                
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                // 验证当前密码
                if (!isset($config['admin_password_hash']) || !password_verify($current_password, $config['admin_password_hash'])) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('当前密码错误，请重试'));
                    exit;
                }
                
                // 确认新密码
                if (empty($new_password)) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('新密码不能为空'));
                    exit;
                }
                
                if ($new_password !== $confirm_password) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('新密码与确认密码不匹配，请重试'));
                    exit;
                }
                  // 生成新密码哈希
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // 保留现有的盐值，或者生成新的盐值
                $security_salt = $config['security_salt'] ?? bin2hex(random_bytes(32));
                
                // 保留GitHub令牌
                $github_token = $config['github_token'] ?? '';
                
                // 创建新的配置文件内容
                $config_content = <<<EOT
<?php
/**
 * 敏感配置信息
 * 
 * 此文件包含敏感信息，不应该被提交到版本控制系统中
 * 请将此文件添加到 .gitignore
 */

// 安全配置
return [
    // 管理员密码的哈希值 (使用 password_hash 函数生成)
    'admin_password_hash' => '{$new_hash}', // 管理员密码哈希
    
    // 用于额外安全的随机盐值 (32字节随机字符串)
    'security_salt' => '{$security_salt}',
    
    // GitHub API访问令牌 (如有)
    'github_token' => '{$github_token}',
];
?>
EOT;
                
                // 写入配置文件
                $config_file = __DIR__ . '/config.php';
                $result = file_put_contents($config_file, $config_content, LOCK_EX);
                $result = file_put_contents($config_file, $config_content, LOCK_EX);
                if ($result === false) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('无法写入配置文件，密码未更改'));
                    exit;
                }
                
                // 更新当前会话中的配置
                $config['admin_password_hash'] = $new_hash;
                
                header('Location: ' . $_SERVER['PHP_SELF'] . '?message=' . urlencode('密码已成功更改'));
                exit;
            }
            break;
            
        case 'generate_hash':
            // 生成密码哈希功能 - 需要登录
            if (!isLoggedIn($config)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('请先登录后再进行此操作'));
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // 验证CSRF令牌
                if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('安全验证失败，请重试'));
                    exit;
                }
                
                $password = $_POST['password'] ?? '';
                
                if (empty($password)) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('密码不能为空'));
                    exit;
                }
                
                // 生成密码哈希
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                // 将哈希传回页面显示
                header('Location: ' . $_SERVER['PHP_SELF'] . '?hash=' . urlencode($hash) . '&message=' . urlencode('密码哈希已生成'));
                exit;
            }
            break;
            
        case 'logout':
            // 处理登出请求
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );            }
            session_destroy();
            header('Location: ' . $_SERVER['PHP_SELF'] . '?message=' . urlencode('已安全退出'));
            exit;
            
        case 'update':
            // 强制更新
            @unlink($config['cache_file']);
            $result = main($config);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
              case 'add_contributor':            // 添加手动贡献者 - 需要登录
            if (!isLoggedIn($config)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('请先登录后再进行此操作'));
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['login'])) {
                // 验证CSRF令牌
                if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('安全验证失败，请重试'));
                    exit;
                }
                
                $stats = loadStatsFile($config['stats_file']);
                
                // 创建新贡献者数据
                $new_contributor = [
                    'login' => htmlspecialchars(trim($_POST['login'])),
                    'avatar_url' => !empty($_POST['avatar_url']) ? 
                        htmlspecialchars(trim($_POST['avatar_url'])) : 
                        'https://github.com/identicons/' . htmlspecialchars(trim($_POST['login'])) . '.png',
                    'contributions' => (int)($_POST['contributions'] ?? 1),
                    'html_url' => 'https://github.com/' . htmlspecialchars(trim($_POST['login'])),
                    'manual' => true
                ];
                
                // 添加到数组
                $stats['manual_contributors'][] = $new_contributor;
                  // 保存到文件
                $result = file_put_contents($config['stats_file'], json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                if ($result === false) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('保存贡献者数据失败'));
                    exit;
                }
                
                // 强制更新缓存
                @unlink($config['cache_file']);
                $result = main($config);
                
                header('Location: ' . $_SERVER['PHP_SELF'] . '?message=' . urlencode('添加贡献者成功'));
                exit;
            }
            break;
            
        case 'remove_contributor':
            // 删除手动贡献者 - 需要登录
            if (!isLoggedIn($config)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=请先登录后再进行此操作');
                exit;
            }
            
            if (isset($_GET['login'])) {
                $stats = loadStatsFile($config['stats_file']);
                $login = $_GET['login'];
                
                foreach ($stats['manual_contributors'] as $key => $contributor) {
                    if ($contributor['login'] === $login) {
                        unset($stats['manual_contributors'][$key]);
                        break;
                    }
                }
                
                // 重新索引数组
                $stats['manual_contributors'] = array_values($stats['manual_contributors']);
                
                // 保存到文件
                file_put_contents($config['stats_file'], json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
                
                // 强制更新缓存
                @unlink($config['cache_file']);
                $result = main($config);
                
                header('Location: ' . $_SERVER['PHP_SELF'] . '?message=删除贡献者成功');
                exit;
            }
            break;
              case 'view':
            // 查看SVG (公开访问)
            if (!file_exists($config['output_svg_path'])) {
                main($config);
            }
            
            // 设置缓存控制头
            header('Content-Type: image/svg+xml');
            header('Cache-Control: max-age=3600, public'); // 1小时客户端缓存
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
            $etag = '"' . md5_file($config['output_svg_path']) . '"';
            header('ETag: ' . $etag);
            
            // 检查客户端缓存是否有效
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
            
            readfile($config['output_svg_path']);
            exit;
    }
} elseif (isset($_GET['update']) && $_GET['update'] === 'true') {
    // 兼容旧版链接
    @unlink($config['cache_file']);
    $result = main($config);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
} elseif (isset($_GET['view']) && $_GET['view'] === 'true') {
    // 兼容旧版链接
    if (!file_exists($config['output_svg_path'])) {
        main($config);
    }
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-cache');
    readfile($config['output_svg_path']);
    exit;
} else {
    // 默认行为：更新并展示结果
    $result = main($config);
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GitHub 贡献者图表生成器</title>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f9fafb;
        }
        h1, h2, h3 {
            color: #2563eb;
            margin-bottom: 20px;
            font-weight: 600;
        }
        h1 {
            margin-bottom: 30px;
        }
        .card {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 30px;
            margin-bottom: 30px;
        }
        .preview {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-top: 20px;
            text-align: center;
        }
        .info {
            background-color: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .warning {
            background-color: #fff7ed;
            border-left: 4px solid #f97316;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .success {
            background-color: #ecfdf5;
            border-left: 4px solid #10b981;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .error {
            background-color: #fee2e2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        code {
            background-color: #f1f1f1;
            padding: 2px 5px;
            border-radius: 4px;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 14px;
        }
        .button {
            display: inline-block;
            background-color: #2563eb;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            margin-right: 10px;
            transition: background-color 0.2s;
            border: none;
            cursor: pointer;
        }
        .button:hover {
            background-color: #1d4ed8;
        }
        .button.secondary {
            background-color: #6b7280;
        }        .button.secondary:hover {
            background-color: #4b5563;
        }
        .button.danger {
            background-color: #ef4444;
        }
        .button.danger:hover {
            background-color: #dc2626;
        }
        .button.small {
            padding: 4px 8px;
            font-size: 12px;
            margin-left: 8px;
        }
        .status {
            margin-top: 20px;
            padding: 15px;
            background-color: #ecfdf5;
            border-radius: 5px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font-size: 16px;
        }
        .tabs {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 20px;
        }
        .tab {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-right: 10px;
        }
        .tab.active {
            border-bottom: 2px solid #2563eb;
            font-weight: 500;
            color: #2563eb;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .contributors-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .contributor-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #f9fafb;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .contributor-card img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin-bottom: 10px;
        }
        .contributor-card .name {
            font-weight: 500;
            margin-bottom: 5px;
        }
        .contributor-card .contributions {
            font-size: 14px;
            color: #6b7280;
        }
        .contributor-card .actions {
            margin-top: 10px;
        }
        .login-form {
            max-width: 400px;
            margin: 0 auto;
        }        .preview-actions {
            margin-top: 15px;
        }
        .svg-container {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            background-color: #f9fafb;
            max-width: 100%;
            overflow: auto;
        }
        .svg-container svg {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }
        @media (max-width: 768px) {
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex-basis: 45%;
                text-align: center;
                margin-bottom: 10px;
            }
            
            .contributors-list {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }
        
        @media (max-width: 480px) {
            .tab {
                flex-basis: 100%;
            }
            
            .auth-status {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>GitHub 贡献者图表生成器</h1>
        
        <?php if (isset($_GET['message'])): ?>
        <div class="success">
            <?php echo htmlspecialchars($_GET['message']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
        <div class="error">
            <?php echo htmlspecialchars($_GET['error']); ?>
        </div>
        <?php endif; ?>
        
        <div class="auth-status">
            <div>
                <?php if (isLoggedIn($config)): ?>
                <strong>状态：</strong> 已登录 (管理员)
                <?php else: ?>
                <strong>状态：</strong> 未登录 (访客模式)
                <?php endif; ?>
            </div>
            <div>
                <?php if (isLoggedIn($config)): ?>
                <a href="?action=logout" class="button secondary">退出登录</a>
                <?php endif; ?>
            </div>
        </div>
          <div class="info">
            <p>当前配置：抓取 <code><?php echo $config['github_username'] . '/' . $config['github_repo']; ?></code> 的贡献者信息</p>
            <p>最后更新时间：<?php echo file_exists($config['cache_file']) ? date('Y-m-d H:i:s', filemtime($config['cache_file'])) : '从未更新'; ?></p>
            <p>直接访问链接：<code><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]?action=view"; ?></code> <button class="button small" onclick="copyToClipboard(this.previousElementSibling.textContent)">复制</button></p>
        </div>
        
        <p>
            <a href="?action=update" class="button">强制更新贡献者数据</a>
            <a href="?action=view" class="button secondary" target="_blank">查看 SVG 图片</a>
        </p>
        
        <?php if (!empty($result)): ?>
        <div class="status">
            <strong>状态：</strong> <?php echo $result['message']; ?><br>
            <strong>时间：</strong> <?php echo $result['time']; ?><br>
            <strong>贡献者数量：</strong> <?php echo $result['contributor_count']; ?>
        </div>
        <?php endif; ?>
    </div>    <div class="tabs">
        <div class="tab active" onclick="openTab(event, 'preview-tab')">图片预览</div>
        <div class="tab" onclick="openTab(event, 'contributors-tab')">管理贡献者</div>
        <?php if (isLoggedIn($config)): ?>
        <div class="tab" onclick="openTab(event, 'password-tab')">密码管理</div>
        <?php else: ?>
        <div class="tab" onclick="openTab(event, 'login-tab')">管理员登录</div>
        <?php endif; ?>
        <div class="tab" onclick="openTab(event, 'help-tab')">使用说明</div>
    </div>
      <?php 
    // 检查是否需要初始密码设置
    $needs_password_setup = empty($config['admin_password_hash']);
    
    // 如果需要设置初始密码，自动显示设置页面
    if ($needs_password_setup): 
    ?>
    <div id="setup-tab" class="tab-content active">
        <div class="card">
            <h2>初始密码设置</h2>
            <div class="warning">
                <p><strong>安全提示：</strong> 您需要设置管理员密码才能使用系统的管理功能。</p>
                <p>请设置一个强密码以保护您的系统安全。</p>
            </div>
            
            <div class="login-form">
                <form action="?action=setup_password" method="post">
                    <div class="form-group">
                        <label for="new_password">新密码</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">确认密码</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit" class="button">设置密码</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div id="preview-tab" class="tab-content <?php echo $needs_password_setup ? '' : 'active'; ?>"><?php // 仅当不需要设置密码时才默认激活 ?>
        <div class="preview">
            <h2>图片预览</h2>
            <div class="svg-container" id="svg-preview-container">
                <?php 
                if (file_exists($config['output_svg_path'])) {
                    echo file_get_contents($config['output_svg_path']);
                } else {
                    echo generateSvg([], $config['github_username'], $config['github_repo']);
                }
                ?>
            </div>
            <div class="preview-actions">
                <button class="button secondary" onclick="downloadSVG()">下载SVG</button>
                <a href="?action=view" class="button secondary" target="_blank">在新窗口打开</a>
            </div>
        </div>
    </div>
    
    <div id="contributors-tab" class="tab-content">
        <div class="card">
            <h2>管理贡献者</h2>
            
            <?php if (!isLoggedIn($config)): ?>
            <div class="warning">
                <p><strong>需要管理员权限：</strong> 请先<a href="javascript:void(0);" onclick="openTab(null, 'login-tab')">登录</a>后再进行贡献者管理操作。</p>
            </div>
            <?php else: ?>
            <div class="warning">
                <p>手动添加的贡献者会与GitHub API返回的贡献者一起显示在SVG图片中。</p>
                <p>这个功能可以用于添加那些对项目有贡献但没有在GitHub上提交代码的人员。</p>
            </div>
            
            <h3>添加贡献者</h3>            <form action="?action=add_contributor" method="post">
                <div class="form-group">
                    <label for="login">GitHub 用户名（必填）</label>
                    <input type="text" id="login" name="login" required>
                </div>
                <div class="form-group">
                    <label for="avatar_url">头像URL（选填，留空将使用GitHub默认头像）</label>
                    <input type="text" id="avatar_url" name="avatar_url" placeholder="https://github.com/identicons/username.png">
                </div>
                <div class="form-group">
                    <label for="contributions">贡献数量</label>
                    <input type="number" id="contributions" name="contributions" value="1" min="1">
                </div>
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <button type="submit" class="button">添加贡献者</button>
            </form>
            <?php endif; ?>
            
            <h3>手动添加的贡献者</h3>
            <?php
            $stats = loadStatsFile($config['stats_file']);
            $manual_contributors = $stats['manual_contributors'] ?? [];
            
            if (empty($manual_contributors)):
            ?>
            <p>暂无手动添加的贡献者。</p>
            <?php else: ?>
            <div class="contributors-list">
                <?php foreach ($manual_contributors as $contributor): ?>
                <div class="contributor-card">
                    <img src="<?php echo htmlspecialchars($contributor['avatar_url']); ?>" alt="<?php echo htmlspecialchars($contributor['login']); ?>">
                    <div class="name"><?php echo htmlspecialchars($contributor['login']); ?></div>
                    <div class="contributions">贡献: <?php echo htmlspecialchars($contributor['contributions']); ?></div>
                    <?php if (isLoggedIn($config)): ?>
                    <div class="actions">
                        <a href="?action=remove_contributor&login=<?php echo urlencode($contributor['login']); ?>" class="button danger" onclick="return confirm('确定要删除此贡献者吗？')">删除</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
      <?php if (!isLoggedIn($config)): ?>
    <div id="login-tab" class="tab-content">
        <div class="card">
            <h2>管理员登录</h2>
            <div class="login-form">                <form action="?action=login" method="post">
                    <div class="form-group">
                        <label for="password">管理员密码</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit" class="button">登录</button>
                </form>
                <p class="info" style="margin-top: 20px;">
                    <small>提示：首次使用请修改脚本中的默认密码。默认密码在 <code>$config</code> 数组中的 <code>admin_password</code> 参数。</small>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (isLoggedIn($config)): ?>
    <div id="password-tab" class="tab-content">
        <div class="card">
            <h2>密码管理</h2>
            
            <!-- 修改密码表单 -->
            <div class="section">
                <h3>修改管理员密码</h3>
                <form action="?action=change_password" method="post">
                    <div class="form-group">
                        <label for="current_password">当前密码</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password">新密码</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">确认新密码</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit" class="button">更改密码</button>
                </form>
            </div>
            
            <!-- 生成密码哈希工具 -->
            <div class="section" style="margin-top: 40px;">
                <h3>密码哈希生成工具</h3>
                <p class="info">此工具可以生成安全的密码哈希值，适用于配置文件中设置密码。</p>
                
                <?php if (isset($_GET['hash'])): ?>
                <div class="success" style="word-break: break-all;">
                    <strong>生成的密码哈希值：</strong><br>
                    <?php echo htmlspecialchars($_GET['hash']); ?>
                    <p><button class="button small" onclick="copyToClipboard('<?php echo htmlspecialchars($_GET['hash']); ?>')">复制哈希值</button></p>
                </div>
                <?php endif; ?>
                
                <form action="?action=generate_hash" method="post">
                    <div class="form-group">
                        <label for="hash_password">输入要哈希的密码</label>
                        <input type="password" id="hash_password" name="password" required>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit" class="button secondary">生成哈希</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div id="help-tab" class="tab-content">
        <div class="card">
            <h2>使用说明</h2>
            <p>1. 修改脚本顶部的 <code>$config</code> 数组以自定义配置：</p>
            <ul>
                <li><code>github_username</code>: GitHub 用户名</li>
                <li><code>github_repo</code>: GitHub 仓库名</li>
                <li><code>github_token</code>: 如果需要更高的 API 请求限制，可以设置个人访问令牌</li>
                <li><code>max_contributors</code>: 最多显示的贡献者数量</li>
                <li><code>admin_password</code>: 管理员密码，请务必修改默认密码</li>
            </ul>
              <p>2. 设置自动更新：</p>
            <p>在服务器上设置一个 Cron 任务，每天执行一次这个脚本：</p>
            <code>0 0 * * * php /path/to/contributors.php</code>
            
            <p>3. 在你的网站上嵌入 SVG：</p>
            <code>&lt;img src="https://your-domain.com/path/to/contributors.php?action=view" alt="Contributors" /&gt;</code>
            
            <p>4. 手动管理贡献者：</p>
            <ul>
                <li>登录管理员账户</li>
                <li>使用"管理贡献者"标签页添加或删除手动贡献者</li>
                <li>手动添加的贡献者信息存储在 <code><?php echo $config['stats_file']; ?></code> 文件中</li>
                <li>您可以直接编辑该文件，但必须保持正确的JSON格式</li>
            </ul>
            
            <p>5. 安全性说明：</p>
            <ul>
                <li>请务必修改默认的管理员密码</li>
                <li>管理员会话有效期：<?php echo $config['admin_session_lifetime'] / 60; ?> 分钟</li>
                <li>SVG图片是公开的，任何人都可以查看</li>
                <li>只有管理员可以添加和删除贡献者</li>
            </ul>
        </div>
    </div>      <script>
    function openTab(evt, tabName) {
        // 隐藏所有标签内容
        var tabcontents = document.getElementsByClassName("tab-content");
        for (var i = 0; i < tabcontents.length; i++) {
            tabcontents[i].classList.remove("active");
        }
        
        // 移除所有标签的活动状态
        var tabs = document.getElementsByClassName("tab");
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.remove("active");
        }
        
        // 显示当前标签内容并激活当前标签
        document.getElementById(tabName).classList.add("active");
        
        // 如果是由事件触发的（而不是直接调用），则激活当前标签
        if (evt) {
            evt.currentTarget.classList.add("active");
        } else {
            // 找到对应的标签并激活
            var tabs = document.getElementsByClassName("tab");
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].textContent.includes(tabName.replace("-tab", "").replace(/^\w/, c => c.toUpperCase()))) {
                    tabs[i].classList.add("active");
                    break;
                }
            }
        }
    }
    
    // 异步更新贡献者数据
    function updateContributorsData() {
        // 显示加载中提示
        const statusElement = document.querySelector('.status') || document.createElement('div');
        statusElement.className = 'status';
        statusElement.innerHTML = '<strong>状态：</strong> 正在更新贡献者数据，请稍候...';
        
        const cardElement = document.querySelector('.card');
        if (!cardElement.contains(statusElement)) {
            cardElement.appendChild(statusElement);
        }
        
        // 使用Fetch API获取数据
        fetch('?action=update')
            .then(response => {
                if (!response.ok) {
                    throw new Error('网络请求失败');
                }
                return response.json();
            })
            .then(data => {
                // 更新状态信息
                statusElement.innerHTML = 
                    `<strong>状态：</strong> ${data.message}<br>` +
                    `<strong>时间：</strong> ${data.time}<br>` +
                    `<strong>贡献者数量：</strong> ${data.contributor_count}`;
                
                // 刷新预览图片（添加时间戳防止缓存）
                const previewImg = document.querySelector('#preview-tab img');
                if (previewImg) {
                    previewImg.src = '?action=view&t=' + new Date().getTime();
                }
            })
            .catch(error => {
                console.error('更新失败:', error);
                statusElement.innerHTML = '<strong>错误：</strong> 更新失败，请稍后重试';
            });
    }    // 监听页面加载事件，为所有按钮添加功能
    document.addEventListener('DOMContentLoaded', function() {
        // 延迟加载预览内容
        const previewTab = document.getElementById('preview-tab');
        const tabButtons = document.querySelectorAll('.tab');
        
        // 监听标签页点击事件
        tabButtons.forEach(button => {
            if (button.textContent.includes('图片预览')) {
                button.addEventListener('click', function(e) {
                    // 首次点击预览时，确保SVG内容已加载
                    if (!previewTab.dataset.loaded) {
                        previewTab.dataset.loaded = 'true';
                        // SVG内容已经在PHP端直接嵌入，不需要额外加载
                    }
                    openTab(e, 'preview-tab');
                });
            } else if (button.textContent.includes('管理贡献者')) {
                button.addEventListener('click', function(e) {
                    openTab(e, 'contributors-tab');
                });
            } else if (button.textContent.includes('管理员登录')) {
                button.addEventListener('click', function(e) {
                    openTab(e, 'login-tab');
                });
            } else if (button.textContent.includes('使用说明')) {
                button.addEventListener('click', function(e) {
                    openTab(e, 'help-tab');
                });
            }
        });
        
        // 替换更新按钮的默认行为
        const updateButton = document.querySelector('a[href="?action=update"]');
        if (updateButton) {
            updateButton.addEventListener('click', function(e) {
                e.preventDefault();
                updateContributorsData();
            });
        }
        
        // 绑定下载SVG按钮
        const downloadButton = document.querySelector('button.button.secondary');
        if (downloadButton && downloadButton.textContent.includes('下载SVG')) {
            downloadButton.addEventListener('click', downloadSVG);
        }
    });
    
    // SVG下载功能    
    function downloadSVG() {
        const svgElement = document.querySelector('#svg-preview-container svg');
        if (!svgElement) {
            alert('未找到SVG内容，无法下载');
            return;
        }
        
        // 创建SVG内容的Blob对象
        const svgData = new XMLSerializer().serializeToString(svgElement);
        const svgBlob = new Blob([svgData], {type: 'image/svg+xml;charset=utf-8'});
        const svgUrl = URL.createObjectURL(svgBlob);
        
        // 创建下载链接
        const downloadLink = document.createElement('a');
        downloadLink.href = svgUrl;
        downloadLink.download = 'contributors.svg';
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
        
        // 释放URL对象
        setTimeout(() => {
            URL.revokeObjectURL(svgUrl);
        }, 100);
    }
      // 复制文本到剪贴板
    function copyToClipboard(text) {
        // 使用较低级别的document.execCommand作为备选方案
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';  // 避免页面滚动
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        let successful = false;
        try {
            // 尝试使用现代API
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text)
                    .then(() => showCopySuccess(event.target))
                    .catch(err => {
                        console.error('剪贴板API失败:', err);
                        // 回退到execCommand
                        successful = document.execCommand('copy');
                        if (successful) {
                            showCopySuccess(event.target);
                        } else {
                            showCopyFailure();
                        }
                    });
            } else {
                // 回退到execCommand
                successful = document.execCommand('copy');
                if (successful) {
                    showCopySuccess(event.target);
                } else {
                    showCopyFailure();
                }
            }
        } catch (err) {
            console.error('复制失败:', err);
            showCopyFailure();
        }
        
        document.body.removeChild(textArea);
    }
    
    function showCopySuccess(element) {
        if (!element) return;
        // 复制成功后给用户反馈
        const originalText = element.textContent;
        element.textContent = '已复制!';
        element.classList.add('success');
        
        // 2秒后恢复原始文本
        setTimeout(() => {
            element.textContent = originalText;
            element.classList.remove('success');
        }, 2000);
    }
    
    function showCopyFailure() {
        alert('复制失败，请手动复制');
    }
    </script>
</body>
</html>
