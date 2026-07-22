# Nâng cấp MaxGuard 1.0.1 cho Laravel 10

## Nếu đang dùng MaxGuard 1.0.0

Backup/commit dự án trước, sau đó chép đè các file 1.0.1 vào đúng thư mục Laravel. Không chép `composer.maxguard.json` thành `composer.json`; file đó chỉ mô tả yêu cầu tương thích.

Thêm vào `.env`:

```dotenv
MAXGUARD_ROUTE_MIDDLEWARE=auth
MAXGUARD_PROVIDE_AUTH_ROUTES=true
```

Làm mới cache và database:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan route:list --name=login
```

Kết quả route phải có ít nhất `login`, `login.store` và `logout`. Tạo admin:

```bash
php artisan maxguard:create-admin
```

Nếu đã seed bản 1.0.0 trước khi có user, chạy lại seeder sau khi tạo admin. Seeder dùng `updateOrCreate`, không tạo trùng website và sẽ gắn các website demo cho user đầu tiên:

```bash
php artisan db:seed --class=MaxGuardSeeder
```

Khởi động lại queue worker sau khi deploy:

```bash
php artisan queue:restart
php artisan test
```

## Nếu dự án đã có auth riêng

Không đăng ký auth tối thiểu của MaxGuard. Giữ named route `login` của Breeze/Jetstream/Fortify và cấu hình:

```dotenv
MAXGUARD_PROVIDE_AUTH_ROUTES=false
MAXGUARD_ROUTE_MIDDLEWARE=auth
```

Sau đó chạy `php artisan optimize:clear`. Nếu vẫn thấy `Route [login] not defined`, auth của host app chưa đăng ký named route `login`; sửa route auth của host app hoặc bật lại `MAXGUARD_PROVIDE_AUTH_ROUTES=true`.

## Kiểm tra nhanh sau nâng cấp

```bash
php artisan route:list --path=login
php artisan route:list --path=logout
php artisan test --filter=AuthenticationTest
php artisan test --filter=OwnerAccessTest
```

Mở `/dashboard` trong cửa sổ ẩn danh: request phải chuyển tới `/login`. Sau khi đăng nhập, truy cập `/login` phải chuyển ngược về `/dashboard`, không phải `/home`.
