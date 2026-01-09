# 📊 Sistema Automático de Migrações de Schema

## O que é?

Um sistema automático que **verifica e cria o schema do banco de dados toda vez que a aplicação inicia**. Isso garante que:

✅ Todas as tabelas necessárias existam  
✅ Todas as colunas estejam presentes  
✅ Índices e constraints estejam configurados corretamente  
✅ Dados padrão (ligas) sejam inseridos  

## Como funciona?

### Fluxo Automático
1. **Primeira carga** → `db()` é chamado
2. **Conexão estabelecida** → `ensureSchema()` é executado
3. **Migrações rodadas** → `runMigrations()` cria/atualiza todas as tabelas
4. **Aplicação segue normalmente** → Com schema garantido

```
User abre página
    ↓
PHP inicia sessão
    ↓
require_once 'backend/db.php'
    ↓
$pdo = db()
    ↓
ensureSchema($pdo)
    ↓
runMigrations()
    ↓
Todas as tabelas criadas/atualizadas
    ↓
Aplicação segue normalmente ✓
```

## Tabelas Gerenciadas

| Tabela | Descrição |
|--------|-----------|
| `leagues` | Ligas (ELITE, NEXT, RISE, ROOKIE) |
| `users` | Usuários e gestores |
| `divisions` | Divisões dentro das ligas |
| `teams` | Times da liga |
| `players` | Elencos dos times |
| `picks` | Draft picks |
| `drafts` | Drafts por ano/liga |
| `draft_players` | Jogadores disponíveis no draft |
| `seasons` | Temporadas |
| `awards` | Prêmios e reconhecimentos |
| `playoff_results` | Resultados de playoffs |
| `directives` | Diretrizes da liga |
| `trades` | Trocas entre times |

## Como Usar

### Automático (Padrão)
Simplesmente use a aplicação normalmente. A migração roda automaticamente ao carregar qualquer página.

### Manual via Web (Admin)
Acesse: `/admin-schema.php` (apenas para administradores)

```
GET /admin-schema.php
GET /admin-schema.php?action=run
```

### Manual via CLI (Developers)
```bash
cd c:\xampp\htdocs\gestordefranquia
C:\xampp\php\php.exe backend/migrations.php
```

Saída JSON:
```json
{
  "success": true,
  "executed": 13,
  "errors": [],
  "timestamp": "2026-01-09 15:30:45"
}
```

## Por que isso evita o problema anterior?

Antes: ❌ Código assumia que colunas existiam  
Resultado: Erro SQL quando coluna faltava  

Agora: ✅ Sistema verifica/cria tudo automaticamente  
Resultado: Sempre funciona, mesmo com schema desatualizado  

## Adicionando Novos Campos

Se precisar adicionar uma coluna nova:

1. **Abra** `backend/migrations.php`
2. **Localize** a migração da tabela apropriada
3. **Adicione** o novo campo ao CREATE TABLE
4. **Salve** e próxima carga rode a migração automaticamente

Exemplo:
```php
'alter_players_add_column' => [
    'sql' => "ALTER TABLE players ADD COLUMN IF NOT EXISTS new_field VARCHAR(100);"
]
```

## Monitoramento

Logs são registrados em:
- `error_log` do PHP (geralmente `/var/log/php-errors.log`)
- Console do navegador (se houver erros)

Procure por: `[MIGRATION]` para ver o que foi executado

## Segurança

⚠️ A página `/admin-schema.php` requer:
- Usuário logado como `admin`
- Sessão PHP válida
- Acesso ao banco de dados

Usuários normais: ❌ Acesso negado (erro 403)

## Troubleshooting

**Erro: "Arquivo schema.sql não encontrado"**
→ Certifique-se que `sql/schema.sql` existe

**Erro: "Access denied for user"**
→ Verifique credenciais em `config.php`

**Erro: "Column not found"**
→ A migração cria a coluna na próxima carga

**Migrações não rodando?**
→ Verifique logs: `C:\xampp\logs\error.log`
