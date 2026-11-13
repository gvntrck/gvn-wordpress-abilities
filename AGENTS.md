# Instruções do plugin

- Sempre que fizer qualquer alteração no plugin, faça também o bump da versão no cabeçalho `Version` de `gvn-wordpress-abilities.php`.
- Use versionamento semântico: patch para correções e ajustes, minor para novas funcionalidades e major para mudanças incompatíveis.
- Mantenha sincronizadas as versões usadas no cache de assets (por exemplo, em `wp_enqueue_style` e `wp_enqueue_script`) e outras referências à versão atual do plugin.
- Sempre que fizer bump da versão, execute `python scripts/build-zip.py` para gerar o ZIP instalável em `dist`; o script remove os ZIPs antigos e mantém apenas `dist/gvn-wordpress-abilities.zip`.

## Verificação local

- Execute `php tests/admin-check.php`, `php tests/plugin-files-check.php`, `php tests/htmlpress-check.php` e `python tests/build-zip-check.py`.
- Neste Windows, há PHP CLI fora do PATH em `C:\Users\Administrador\AppData\Roaming\Local\lightning-services\php-8.3.29+1\bin\win64\php.exe`. O Ubuntu do WSL também oferece PHP CLI; nele é possível verificar links simbólicos sem a restrição de criação do Windows.
