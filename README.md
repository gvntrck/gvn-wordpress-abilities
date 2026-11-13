# GVN WordPress Abilities

Plugin de Giovani — https://projetoalfa.org.

## Requisito obrigatório

Instale e ative o **[MCP Adapter](https://wordpress.org/plugins/mcp-adapter/), por WordPress.org**, antes de ativar o GVN WordPress Abilities. A dependência está declarada no cabeçalho do plugin (`Requires Plugins: mcp-adapter`), permitindo que o WordPress bloqueie a ativação enquanto o MCP Adapter não estiver instalado e ativo.

Adiciona abilities para uso com o MCP Adapter. Atualmente permite listar tabelas do prefixo do site e consultar colunas e índices, sem ler registros nem executar consultas arbitrárias. Exige WordPress 6.9+, PHP 7.4+ e MCP Adapter.

O acesso é restrito a administradores; em multisite, a superadministradores. Nomes e estruturas das tabelas são enviados ao cliente que executar as abilities.

## Gerenciar habilidades

No painel do WordPress, acesse **Configurações → GVN Abilities**. A tela lista todas as habilidades deste plugin, com identificador, breve descrição e estado salvo.

- **Listar tabelas do site:** lista os nomes das tabelas do prefixo do site atual.
- **Descrever tabela do site:** consulta as colunas e os índices de uma tabela, sem ler seus registros.

Marque **Desativar** nas habilidades que deseja bloquear e clique em **Salvar alterações**. Para reativar, desmarque e salve. Por padrão, ambas ficam ativas. Habilidades desativadas não são registradas na API e deixam de estar disponíveis para execução pelo MCP. Se o cliente mantiver uma lista em cache, atualize a descoberta de ferramentas ou reconecte-o após salvar.

Em multisite, o gerenciamento é feito por superadministradores, nas configurações de cada site. A tela não altera habilidades do WordPress ou de outros plugins.
