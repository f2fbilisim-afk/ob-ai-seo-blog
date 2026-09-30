# Kurulum / güncelleme

WordPress **Eklentiler** listesindeki sürüm, `ob-ai-seo-blog.php` dosyasındaki **Version** satırından okunur.

## Sürüm hâlâ 1.0.0 görünüyorsa

1. **Eklentiyi etkisizleştir** (silme).
2. FTP veya Dosya Yöneticisi ile `wp-content/plugins/ob-ai-seo-blog` klasörünü **tamamen silin**.
3. [Son release zip](https://github.com/f2fbilisim-afk/ob-ai-seo-blog/releases/latest) indirin.
4. WordPress → **Eklentiler → Yeni ekle → Eklenti yükle** → zip → **Etkinleştir**.
5. Listede **Sürüm 1.0.4** (veya güncel) ve **Geliştirici: F2F Bilişim** görünmeli.

Aynı sunucuda `ob-ai-seo-blog-1.0.x` gibi **ikinci klasör** varsa silin; yalnızca `ob-ai-seo-blog` kalsın.

## Otomatik güncelleme

1.0.2+ sürümlerde **Güncellemeler** veya eklenti satırındaki **Güncelle** kullanılabilir. Manifest sırası: `api.f2fbilisim.com` → GitHub.
