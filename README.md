# GVN WordPress Abilities

Plugin de Giovani — https://projetoalfa.org.

## Requisito obrigatório

Instale e ative o **[MCP Adapter](https://wordpress.org/plugins/mcp-adapter/), por WordPress.org**, antes de ativar o GVN WordPress Abilities. A dependência está declarada no cabeçalho do plugin (`Requires Plugins: mcp-adapter`), permitindo que o WordPress bloqueie a ativação enquanto o MCP Adapter não estiver instalado e ativo.

Adiciona abilities para uso com o MCP Adapter. Permite listar tabelas do prefixo do site, consultar colunas e índices, ler arquivos de plugins instalados e gerenciar páginas HTMLPress. Não executa consultas SQL arbitrárias nem edita arquivos; páginas PHP publicadas pelo HTMLPress executam código no servidor. Exige WordPress 6.9+, PHP 7.4+ e MCP Adapter; a integração HTMLPress exige os requisitos do próprio HTMLPress (PHP 8.1+).

O acesso é restrito a administradores; em multisite, a superadministradores. Nomes e estruturas das tabelas são enviados ao cliente que executar as abilities.

## Gerenciar habilidades

No painel do WordPress, acesse **Configurações → GVN Abilities**. A tela lista todas as habilidades deste plugin, com identificador, breve descrição e estado salvo.

- **Listar tabelas do site:** lista os nomes das tabelas do prefixo do site atual.
- **Descrever tabela do site:** consulta as colunas e os índices de uma tabela, sem ler seus registros.
- **Ler arquivo de plugin:** lê códigos e textos UTF-8 de qualquer plugin instalado, incluindo `vendor` e `node_modules`, em partes de até 256 KiB por chamada. Exige também a permissão `edit_plugins`; configurações como `DISALLOW_FILE_EDIT` podem impedir o acesso.
- **Gerenciar páginas HTMLPress:** lista, lê, cria e edita grupos de páginas pelo fluxo de validação e gravação do HTMLPress. Exige HTMLPress compatível e todas as permissões exigidas por ele.

Marque **Desativar** nas habilidades que deseja bloquear e clique em **Salvar alterações**. Para reativar, desmarque e salve. Por padrão, todas ficam ativas. Habilidades desativadas não são registradas na API e deixam de estar disponíveis para execução pelo MCP. Se o cliente mantiver uma lista em cache, atualize a descoberta de ferramentas ou reconecte-o após salvar.

Em multisite, o gerenciamento é feito por superadministradores, nas configurações de cada site. A tela não altera habilidades do WordPress ou de outros plugins.

## Ler arquivos de plugins

Use `gvn-wordpress-abilities/read-plugin-file` com o identificador do plugin (arquivo principal relativo à pasta de plugins) e, opcionalmente, o arquivo desejado, também relativo à pasta de plugins:

```json
{
  "plugin": "gvn-wordpress-abilities/gvn-wordpress-abilities.php",
  "file": "gvn-wordpress-abilities/admin.php"
}
```

Sem `file`, lê o arquivo principal. Retorna `plugin`, `file`, `offset`, `bytes`, `next_offset` e `content`. Para ler arquivos grandes integralmente, repita a chamada passando `offset` igual ao `next_offset` recebido, até retornar `null`. Aceita qualquer extensão de código ou texto UTF-8, inclusive arquivos sem extensão, nunca os executa. Não exclui `vendor` ou `node_modules`. Arquivos fora da pasta do plugin selecionado, caminhos inválidos, links simbólicos que escapem da pasta permitida e conteúdo binário são bloqueados. Não inclui mu-plugins. A leitura é feita arquivo por arquivo, não em uma única resposta com todos os plugins.

**Atenção:** o conteúdo integral é enviado ao cliente MCP e pode conter segredos embutidos no código. Use apenas clientes de confiança ou desative esta habilidade na tela administrativa.

## Compatibilidade HTMLPress

`gvn-wordpress-abilities/manage-htmlpress` integra a API pública do **HTMLPress** (`HTMLPress::save_group`), não o plugin homônimo do WordPress.org. HTML mantém compatibilidade com a API 1.3.1; publicar PHP exige a API 1.4.1. Sem a API compatível, a operação é bloqueada; as demais habilidades continuam funcionando.

- Listar: `{"action":"list","page":1}`. Retorna até 20 páginas (uma por idioma), cada uma com `type` (`html` ou `php`), e `next_page`; repita enquanto não for `null`. Não inclui lixeira.
- Ler: `{"action":"read","id":123}`. Qualquer ID do grupo serve. Retorna `pages`, o código original em `documents`, os formatos por idioma em `types` e `revision`. A leitura não executa PHP.
- Criar: envie `action=save`, `title`, `slug` e `documents.pt`. `documents.en` e `documents.es` são opcionais.
- Editar: envie `action=save`, `id` e `expected_revision` igual à `revision` da leitura mais recente. Envie apenas os idiomas que deseja substituir. Título e slug omitidos são mantidos; quando enviados, afetam todo o grupo.
- Formatos: `types` aceita `html` ou `php` somente para idiomas enviados em `documents`. Omitir preserva o formato existente na edição; páginas antigas sem formato e novos idiomas usam `html`. Para converter um idioma, envie seu código completo e o formato desejado. Grupos podem misturar HTML e PHP.

Exemplo de criação:

```json
{
  "action": "save",
  "title": "Sobre",
  "slug": "sobre",
  "documents": {
    "pt": "<!doctype html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><title>Sobre</title></head><body><h1>Sobre</h1></body></html>"
  }
}
```

Exemplo de edição (substitua `expected_revision` pelo hash real da leitura):

```json
{
  "action": "save",
  "id": 123,
  "expected_revision": "HASH_SHA256_RETORNADO_POR_READ",
  "documents": {
    "pt": "<!doctype html><html><head><title>Sobre</title></head><body><h1>Conteúdo atualizado</h1></body></html>"
  }
}
```

Exemplo de criação PHP:

```json
{
  "action": "save",
  "title": "Página dinâmica",
  "slug": "pagina-dinamica",
  "types": { "pt": "php" },
  "documents": {
    "pt": "<?php defined('ABSPATH') || exit; ?><!doctype html><html><head><meta charset=\"utf-8\"><?php wp_head(); ?></head><body><h1><?= esc_html(get_bloginfo('name')) ?></h1><?php wp_footer(); ?></body></html>"
  }
}
```

PHP roda com WordPress e plugins já carregados: não inclua `wp-load.php`. Use `$args['assets_url']` para assets existentes e `$args['page_url']` para formulários; `__DIR__` e `__FILE__` apontam para um arquivo temporário, não para o pacote. A página deve incluir os hooks necessários, sem duplicar `wp_head()`/`wp_footer()` quando usar cabeçalho e rodapé do tema. Não use PHP auxiliar nem `exit`/`die` no fluxo normal.

**Atenção:** novas páginas e novas traduções são publicadas imediatamente pelo HTMLPress; páginas existentes mantêm seu status. Não há criação como rascunho nesta API. O código HTML/PHP, de até 2 MiB por idioma, é validado pelo HTMLPress sem remover scripts, CSS ou barras invertidas. Publique apenas código confiável. PHP não possui sandbox: executa com os privilégios do WordPress, incluindo acesso ao banco e aos arquivos. A validação de sintaxe não garante segurança nem funcionamento; formulários que alteram dados precisam de nonces, validação e permissões no próprio template.

Exige `manage_options`, `unfiltered_html`, `edit_pages`, `publish_pages`, `upload_files` e permissão de edição de todas as páginas do grupo; em multisite, exige também superadministrador. Enviar PHP exige adicionalmente `edit_themes` e respeita `DISALLOW_FILE_EDIT` e `DISALLOW_FILE_MODS`. Esses bloqueios não desativam PHP já publicado; editar somente título/slug ou outros idiomas HTML não reenvia o PHP omitido.

A revisão inclui os formatos e detecta alterações anteriores à verificação, não é um bloqueio transacional contra gravações simultâneas pelo painel. Após atualizar este plugin, faça nova leitura antes de editar. Idiomas omitidos e assets já enviados são preservados. Esta habilidade não envia ZIP, não modifica arquivos/assets, não exclui traduções nem altera status. Em páginas com bundles, referências relativas continuam usando a base de assets do HTMLPress. Alterar o slug muda as URLs sem criar redirecionamentos.

Verificações locais, sem WordPress/banco: `php tests/admin-check.php`, `php tests/plugin-files-check.php` e `php tests/htmlpress-check.php`. O último usa um substituto da API HTMLPress para testar o contrato da integração; não substitui um teste em homologação com os plugins reais.
