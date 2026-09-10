# Mirza Custom

نصب و بروزرسانی ربات روی سرور لینوکس (Ubuntu / Debian) با یک دستور. اسکریپت باید با کاربر **root** اجرا شود.

## نصب سریع / Quick install

```bash
bash <(curl -Ls https://raw.githubusercontent.com/MoonofLuna/MirzaCustom/main/install.sh)
```

اگر کاربر root نیستید:

```bash
sudo bash <(curl -Ls https://raw.githubusercontent.com/MoonofLuna/MirzaCustom/main/install.sh)
```

## بروزرسانی / Update

همان دستور بالا را دوباره اجرا کنید و از منو گزینهٔ بروزرسانی را انتخاب کنید. تنظیمات و دیتابیس حفظ می‌شود.

## پیش‌نیازها / Requirements

- Ubuntu 20.04+ یا Debian 11+
- دسترسی root
- یک دامنه که به IP سرور اشاره کند (برای SSL و پنل تحت وب)

لاگ نصب: `/var/log/mirza_installer.log`
