# Painel Administrativo - Gestor de Franquia

## Visão Geral

O painel administrativo foi completamente reconstruído para fornecer controle total sobre as 4 ligas (ELITE, PRIME, RISE, ROOKIE). Agora o admin tem acesso a 4 abas principais com funcionalidades completas.

## Funcionalidades

### 1. **Aba Ligas** 🏆
Gerencie as configurações de cada liga:
- **CAP Mínimo e Máximo**: Configure os limites de salary cap para cada liga
- **Visualização**: Veja quantos times existem em cada liga
- **Salvar em lote**: Salve todas as configurações de uma vez

### 2. **Aba Times** 👥
Controle total sobre os times:
- **Filtrar por Liga**: Visualize times de uma liga específica ou todas
- **Informações Detalhadas**: Veja proprietário, conferência, divisão, CAP Top 8, e número de jogadores
- **Editar Times**: Modifique cidade, nome, mascote, e conferência de qualquer time
- **Visualização em Tabela**: Interface clara e organizada

### 3. **Aba Elencos** 👤
Gerencie os jogadores de qualquer time:
- **Seleção de Time**: Escolha qualquer time do dropdown
- **Visualização Completa**: Veja todos os jogadores com posição, idade, OVR, e papel
- **Editar Jogadores**: 
  - Alterar posição, OVR, e papel
  - **Transferir para outro time** (controle completo)
- **Deletar Jogadores**: Remova jogadores do sistema
- **Visualizar Picks**: Veja todos os draft picks do time

### 4. **Aba Trades** ↔️
Gestão completa de trocas:
- **Filtros**: Visualize trades pendentes, aceitas, rejeitadas ou todas
- **Detalhes Completos**: Veja todos os jogadores e picks envolvidos em cada trade
- **Cancelar Trades**: Cancele qualquer trade pendente
- **REVERTER TRADES**: 🔄 **NOVA FUNCIONALIDADE**
  - Reverta trades já aceitas
  - Todos os jogadores e picks voltam automaticamente para os times originais
  - Útil para desfazer trades problemáticas ou injustas

## API Endpoints

### GET Endpoints
- `GET /api/admin.php?action=leagues` - Lista todas as ligas com configurações
- `GET /api/admin.php?action=teams&league=ELITE` - Lista times (opcional filtro por liga)
- `GET /api/admin.php?action=team_details&team_id=123` - Detalhes completos de um time
- `GET /api/admin.php?action=trades&status=pending` - Lista trades (opcional filtro por status)
- `GET /api/admin.php?action=divisions&league=ELITE` - Lista divisões de uma liga

### PUT Endpoints
- `PUT /api/admin.php?action=league_settings` - Atualiza configurações de liga
- `PUT /api/admin.php?action=team` - Atualiza informações de time
- `PUT /api/admin.php?action=player` - Atualiza jogador ou transfere para outro time
- `PUT /api/admin.php?action=cancel_trade` - Cancela uma trade
- `PUT /api/admin.php?action=revert_trade` - **Reverte uma trade aceita**

### DELETE Endpoints
- `DELETE /api/admin.php?action=player&id=123` - Deleta um jogador

## Segurança

- ✅ Todas as rotas verificam se o usuário é admin
- ✅ Validação de dados em todas as requisições
- ✅ Transações de banco de dados para operações críticas
- ✅ Confirmações para ações destrutivas

## Interface

- 🎨 Design moderno com tema escuro
- 🔶 Identidade visual laranja/preta mantida
- 📱 Responsivo para dispositivos móveis
- ⚡ Carregamento rápido com spinners
- 🎯 Navegação intuitiva com tabs

## Como Usar

1. Acesse `/admin.php` (apenas usuários admin)
2. Use as tabs para navegar entre as funcionalidades
3. Todas as alterações são salvas automaticamente com feedback visual
4. Use os filtros para encontrar rapidamente o que procura

## Notas Importantes

- **Reversão de Trades**: Esta é uma funcionalidade poderosa. Use com cuidado pois altera o estado dos times.
- **Transferências de Jogadores**: Ao transferir um jogador via aba Elencos, ele muda de time imediatamente.
- **Deletar Jogadores**: Esta ação é permanente e não pode ser desfeita.

## Tecnologias

- **Backend**: PHP 7.4+ com PDO
- **Frontend**: JavaScript Vanilla + Bootstrap 5.3
- **Banco de Dados**: MySQL/MariaDB
- **Icons**: Bootstrap Icons 1.11
