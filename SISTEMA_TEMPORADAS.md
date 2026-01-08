# 🏀 Sistema de Temporadas, Draft e Rankings - Documentação Completa

## 📋 Visão Geral

Sistema completo de gerenciamento de temporadas com:
- ✅ Sprints por liga (ELITE: 20 temporadas, NEXT: 15, RISE/ROOKIE: 10)
- ✅ Sistema de Draft automático
- ✅ Ranking acumulativo (nunca reseta)
- ✅ Histórico de campeões e premiações
- ✅ Geração automática de picks por temporada

---

## 🗄️ Estrutura do Banco de Dados

### Tabelas Criadas:

1. **sprints** - Ciclos de temporadas por liga
2. **seasons** - Temporadas individuais dentro de cada sprint
3. **draft_pool** - Jogadores disponíveis para draft
4. **season_standings** - Classificação da temporada regular
5. **playoff_results** - Resultados dos playoffs
6. **season_awards** - Premiações (MVP, DPOY, MIP, 6TH MAN, Champion, Runner-up)
7. **team_ranking_points** - Pontos acumulativos do ranking (NUNCA RESETA)
8. **league_sprint_config** - Configuração de quantas temporadas por sprint

### Views Criadas:

1. **vw_global_ranking** - Ranking geral de todos os times
2. **vw_league_ranking** - Ranking por liga
3. **vw_champions_history** - Histórico de campeões e vices

---

## 🚀 Como Funciona

### 1. Criação de Temporada

Quando o admin cria uma nova temporada:

```javascript
createNewSeason('ELITE')
```

O sistema automaticamente:
- ✅ Cria ou usa o sprint ativo da liga
- ✅ Verifica se não excedeu o limite (ELITE=20, NEXT=15, RISE/ROOKIE=10)
- ✅ Cria a temporada com status 'draft'
- ✅ **GERA AUTOMATICAMENTE** 2 picks (1ª e 2ª rodada) para CADA time da liga
- ✅ Jogadores NÃO podem criar picks manualmente (auto_generated=1)

### 2. Gerenciamento do Draft

**Admin:**
- Adiciona jogadores ao draft_pool da temporada
- Cada jogador tem: nome, posição, idade, OVR, foto, bio, pontos fortes/fracos
- Status: 'available' ou 'drafted'

**Jogadores (usuários):**
- Apenas VISUALIZAM os jogadores disponíveis
- NÃO podem draftar por conta própria
- Admin atribui jogadores aos times

### 3. Sistema de Pontos (Ranking)

#### Temporada Regular:
- 1º lugar: **+4 pontos**
- 2º ao 4º lugar: **+3 pontos**
- 5º ao 8º lugar: **+2 pontos**

#### Playoffs:
- 1ª Rodada: **+1 ponto**
- 2ª Rodada: **+2 pontos**
- Final de Conferência: **+3 pontos**
- Vice Campeão: **+2 pontos**
- Campeão: **+5 pontos**

#### Prêmios Individuais:
- MVP: **+1 ponto**
- DPOY: **+1 ponto**
- MIP: **+1 ponto**
- 6TH MAN: **+1 ponto**

**IMPORTANTE:** Os pontos do ranking NUNCA resetam, são acumulativos eternamente.

### 4. Fim de Sprint

Quando um sprint completa todas as temporadas (ex: ELITE completa 20):

```javascript
// Admin precisa criar novo sprint
// Isso reseta: jogadores, trades, standings
// Mas mantém: ranking de pontos, histórico de campeões
```

---

## 🔧 Instalação

### Passo 1: Executar Migration

```sql
-- No phpMyAdmin ou terminal MySQL
source /path/to/sql/create_seasons_system.sql
```

### Passo 2: Atualizar Admin Panel

Adicionar no `admin.js` (dentro do switch de `appState.view`):

```javascript
case 'seasons':
    showSeasonsManagement();
    break;
case 'draft':
    showDraftManagement(appState.currentSeason, appState.currentLeague);
    break;
case 'ranking':
    showRankingPage('global');
    break;
```

### Passo 3: Adicionar Links no Menu

No arquivo `admin.php`, adicionar:

```html
<li><a href="#" onclick="appState.view='seasons'; showSeasonsManagement()">
    <i class="bi bi-calendar-event"></i> Temporadas
</a></li>
<li><a href="#" onclick="appState.view='ranking'; showRankingPage('global')">
    <i class="bi bi-trophy"></i> Rankings
</a></li>
```

### Passo 4: Incluir Scripts

No final do `admin.php`, adicionar:

```html
<script src="/js/seasons.js"></script>
```

---

## 📱 Fluxo de Uso

### Para o Admin:

1. **Criar Nova Temporada**
   - Admin > Temporadas > Criar Nova Temporada
   - Seleciona a liga (ELITE, NEXT, RISE ou ROOKIE)
   - Sistema cria temporada e gera picks automaticamente

2. **Adicionar Jogadores ao Draft**
   - Admin > Temporadas > [Temporada] > Draft
   - Clica em "Adicionar Jogador"
   - Preenche: nome, posição, idade, OVR, foto, bio, etc.

3. **Realizar Draft**
   - Admin atribui cada jogador do draft a um time
   - Jogador é automaticamente adicionado ao elenco do time

4. **Registrar Resultados da Temporada**
   - Admin registra posições da temporada regular
   - Admin registra resultados dos playoffs
   - Admin registra premiações (MVP, DPOY, etc.)

5. **Sistema Calcula Pontos Automaticamente**
   - Pontos são salvos na tabela `team_ranking_points`
   - Ranking é atualizado automaticamente

### Para os Jogadores (Usuários):

1. **Visualizar Draft**
   - Vê todos os jogadores disponíveis para draft
   - Não pode draftar, apenas visualizar

2. **Visualizar Ranking**
   - Vê ranking geral ou por liga
   - Vê seus pontos acumulados
   - Vê histórico de títulos

3. **Visualizar Picks**
   - Picks são geradas automaticamente
   - Não pode criar/deletar picks
   - Apenas visualiza suas picks da temporada

---

## 🎯 Próximos Passos para Implementação

### 1. Criar Interface de Gerenciamento de Temporadas

Adicionar ao `admin.js`:

```javascript
async function showSeasonsManagement() {
    appState.view = 'seasons';
    updateBreadcrumb();
    
    const container = document.getElementById('mainContainer');
    container.innerHTML = `
        <h4 class="text-white mb-4">Gerenciar Temporadas</h4>
        
        <div class="row g-4 mb-4">
            ${['ELITE', 'NEXT', 'RISE', 'ROOKIE'].map(league => `
                <div class="col-md-3">
                    <div class="bg-dark-panel border-orange rounded p-3">
                        <h5 class="text-orange mb-3">${league}</h5>
                        <button class="btn btn-orange btn-sm w-100 mb-2" onclick="createNewSeason('${league}')">
                            Nova Temporada
                        </button>
                        <button class="btn btn-outline-orange btn-sm w-100" onclick="loadLeagueSeasons('${league}')">
                            Ver Histórico
                        </button>
                    </div>
                </div>
            `).join('')}
        </div>
        
        <div id="seasonsListContainer"></div>
    `;
}
```

### 2. Criar Página de Ranking para Usuários

Criar arquivo `ranking.php`:

```php
<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
// Similar ao dashboard.php mas com ranking
?>
```

### 3. Atualizar Sistema de Picks

No `api/picks.php`, remover a opção de criar picks manualmente:

```php
// Bloquear criação manual de picks
if ($action === 'create') {
    throw new Exception('Picks são geradas automaticamente pelo sistema de temporadas');
}
```

### 4. Página de Draft para Usuários

Criar `draft.php` para jogadores visualizarem os players disponíveis:

```php
<?php
// Página onde usuários veem os jogadores do draft
// MAS não podem draftar, apenas visualizar
?>
```

---

## ⚠️ Considerações Importantes

1. **Picks Automáticas**: Ao criar uma temporada, o sistema gera 2 picks para cada time. Usuários NÃO podem criar/deletar picks.

2. **Ranking Permanente**: Os pontos do ranking NUNCA resetam. São acumulativos para sempre.

3. **Reset de Sprint**: Quando um sprint completa (ex: 20 temporadas na ELITE), o admin precisa iniciar um novo sprint. Isso reseta jogadores e trades, mas mantém o ranking.

4. **Draft Pool Separado**: Jogadores do draft ficam em tabela separada (`draft_pool`) até serem atribuídos a um time.

5. **Premiações**: Cada premiação vale +1 ponto. Admin registra quem ganhou cada prêmio.

---

## 📊 Queries Úteis

```sql
-- Ver ranking de uma liga
SELECT * FROM vw_league_ranking WHERE league = 'ELITE';

-- Ver ranking geral
SELECT * FROM vw_global_ranking;

-- Ver histórico de campeões
SELECT * FROM vw_champions_history;

-- Ver temporada atual
SELECT * FROM seasons WHERE league = 'ELITE' AND status != 'completed' ORDER BY id DESC LIMIT 1;

-- Ver picks de um time na temporada
SELECT * FROM picks WHERE team_id = 1 AND season_id = 1;
```

---

## 🎮 Exemplo de Uso Completo

1. Admin cria temporada 1 da ELITE
2. Sistema gera 8 picks (2 para cada um dos 4 times)
3. Admin adiciona 30 jogadores ao draft
4. Admin atribui jogadores aos times conforme o draft
5. Temporada regular acontece
6. Admin registra: 1º lugar = Time A (4 pontos)
7. Playoffs acontecem
8. Admin registra: Time A campeão (5 pontos) + 1 ponto MVP
9. Time A acumula: 4 + 5 + 1 = 10 pontos no ranking
10. Temporada 2 começa, mas Time A mantém seus 10 pontos

---

## ✅ Checklist de Implementação

- [ ] Executar migration SQL
- [ ] Adicionar `seasons.js` ao admin
- [ ] Adicionar links no menu do admin
- [ ] Criar interface de gerenciamento de temporadas
- [ ] Criar interface de gerenciamento de draft
- [ ] Atualizar sistema de picks (bloquear criação manual)
- [ ] Criar página de ranking para usuários
- [ ] Criar página de visualização de draft para usuários
- [ ] Testar criação de temporada e geração de picks
- [ ] Testar atribuição de draft picks
- [ ] Testar cálculo de pontos do ranking

---

**Sistema criado por:** GitHub Copilot
**Data:** 8 de janeiro de 2026
