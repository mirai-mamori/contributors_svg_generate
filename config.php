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
    'admin_password_hash' => '', // 初始为空，系统会引导用户创建密码
    
    // 用于额外安全的随机盐值 (自动生成的32字节随机字符串)
    'security_salt' => '', // 初始为空，系统会自动生成随机盐值
    
    // GitHub API访问令牌 (如有)
    'github_token' => '',
];
?>
