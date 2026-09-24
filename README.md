# 社区便民留言板

基于 PHP 原生开发的社区便民留言板网站，支持居民求助、意见建议、失物招领等功能。

## 功能特性

- **首页展示**：滚动显示最新留言信息，统计各类留言数量
- **留言发布**：用户可提交留言，选择类型（求助/建议/失物招领），支持图片上传
- **排序筛选**：支持按时间/热度排序，按类型筛选
- **后台管理**：管理员可审核、通过、拒绝、删除留言
- **响应式布局**：适配手机和电脑端

## 技术栈

- **后端**：PHP 原生开发
- **数据库**：MySQL
- **前端**：HTML5 + CSS3 + JavaScript（原生）
- **特性**：响应式设计、图片上传、分页、搜索

## 安装部署

### 环境要求

- PHP >= 7.4
- MySQL >= 5.7
- Apache / Nginx

### 安装步骤

1. 将项目文件上传到 Web 服务器根目录

2. 访问安装脚本创建数据库：
   ```
   http://your-domain/install.php
   ```

3. 安装完成后删除 `install.php` 文件

> 已有环境升级时，请额外执行幂等提交所需的迁移：
> ```bash
> mysql -u root -p community_board < database/migration_add_client_token.sql
> ```

4. 访问首页：
   ```
   http://your-domain/index.php
   ```

5. 访问后台：
   ```
   http://your-domain/admin/login.php
   ```

### 默认管理员账号

- 用户名：`admin`
- 密码：`admin123`

## 项目结构

```
label-9900013/
├── index.php              # 首页
├── submit.php             # 发布留言页
├── detail.php             # 留言详情页
├── install.php            # 安装脚本
├── config/
│   └── database.php       # 数据库配置
├── includes/
│   ├── functions.php      # 公共函数
│   ├── header.php         # 前台头部
│   └── footer.php         # 前台底部
├── api/
│   └── submit.php         # 留言提交API
├── admin/
│   ├── index.php          # 后台管理页
│   ├── login.php          # 后台登录
│   ├── api.php            # 后台API
│   ├── logout.php         # 退出登录
│   └── header.php         # 后台头部
├── assets/
│   ├── css/
│   │   └── style.css      # 样式文件
│   └── js/
│       └── main.js        # 脚本文件
└── database/
    ├── migration_add_client_token.sql  # 幂等提交（重试不重复）
    ├── migration_add_favorites.sql     # 收藏功能
    └── migration_add_reports.sql       # 举报功能
```

## 数据库配置

编辑 `config/database.php` 文件：

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'community_board');
```

## 使用说明

### 前台功能

1. **浏览留言**：首页展示所有已审核通过的留言
2. **筛选类型**：点击类型标签筛选特定类型的留言
3. **排序方式**：支持按时间或热度排序
4. **发布留言**：点击"发布留言"进入提交页面
5. **查看详情**：点击留言卡片查看完整内容

### 后台管理

1. 登录后台管理系统
2. 查看所有留言（支持状态、类型筛选和关键词搜索）
3. 审核留言（通过/拒绝）
4. 删除不当留言
5. 查看留言详情

## 注意事项

- 安装完成后务必删除 `install.php`
- 修改默认管理员密码
- 确保 `uploads/` 目录有写入权限
- 建议配置 HTTPS 保障数据传输安全

## 数据一致性说明

- **幂等提交**：提交页每次加载生成 `client_token`，网络中断后直接重试不会产生第二条待审记录；服务端通过唯一键保证同一凭证只入库一次。
- **图片校验**：前后端均校验 JPG/PNG/GIF/WebP 与 5MB 限制；服务端以文件内容（finfo + getimagesize）识别真实图片，并要求扩展名与真实格式一致，格式不符会明确提示原因且保留已填文字。
- **图片同步**：列表「有图」标记、详情页、后台审核/举报查看统一以“数据库有路径且磁盘文件存在”为准；上传落盘与入库在事务内完成，失败自动清理文件。
- **删除清理**：后台删除留言、举报处理中删除留言时，图片文件同步删除，收藏/举报记录通过外键 `ON DELETE CASCADE` 清理，不留孤立数据。
- **状态展示**：待审核、已拒绝、已删除、参数错误与网络/数据库异常均有独立提示页，接口失败提供原因与重试入口。
