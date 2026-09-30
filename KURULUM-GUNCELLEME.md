# Kurulum / güncelleme (önemli)

## Hâlâ “OB AI SEO Blog · Sürüm 1.0.0 · Geliştirici OB” görüyorsanız

Bu **eski dosya**dır; üzerine yazma çoğu hostta **yeni kodu devreye almaz**. İki eklenti yan yana kalır, WordPress eskisini çalıştırır.

### Doğru kurulum (1.1.0)

1. **Eklentiler** → **OB AI SEO Blog** (1.0.0) → **Etkisizleştir** → **Sil**
2. FTP / Dosya Yöneticisi: `wp-content/plugins/` altında şunları arayın ve **silin**:
   - `ob-ai-seo-blog`
   - `ob-ai-seo-blog-1`, `ob-ai-seo-blog-1.0.4` vb.
3. Zip: https://github.com/f2fbilisim-afk/ob-ai-seo-blog/releases/download/v1.1.0/ob-ai-seo-blog-1.1.0.zip
4. **Eklentiler → Yeni ekle → Eklenti yükle** → zip → **Etkinleştir**
5. Listede **F2F AI SEO Blog · Sürüm 1.1.0 · F2F Bilişim** görünmeli (eski “OB” satırı gitmeli).

Hosting **OPcache** açıksa kurulumdan sonra PHP OPcache / LiteSpeed “Flush” yapın.

Doğrulama: eklenti satırında küçük yazı ile dosya yolu görünür, örn. `ob-ai-seo-blog/ob-ai-seo-blog.php` ve build `20260930-1.1.0`.
