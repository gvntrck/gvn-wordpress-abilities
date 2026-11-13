# Instruções do plugin

- Sempre que fizer qualquer alteração no plugin, faça também o bump da versão no cabeçalho `Version` de `gvn-wordpress-abilities.php`.
- Use versionamento semântico: patch para correções e ajustes, minor para novas funcionalidades e major para mudanças incompatíveis.
- Mantenha sincronizadas as versões usadas no cache de assets (por exemplo, em `wp_enqueue_style` e `wp_enqueue_script`) e outras referências à versão atual do plugin.
