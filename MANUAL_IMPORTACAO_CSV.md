# Sistema de Importação de Jogadores do Draft via CSV

## Como Usar

### 1. Acesse a página de importação
Acesse: `https://seusite.com/import-draft-players.php`

> ⚠️ **Apenas administradores** têm acesso a esta página

### 2. Formato do arquivo CSV

O arquivo CSV deve ter **exatamente** estas colunas na primeira linha:

```csv
nome,posicao,idade,ovr
```

Você também pode usar os nomes em inglês:

```csv
name,position,age,ovr
```

### 3. Exemplo de CSV

```csv
nome,posicao,idade,ovr
LeBron James,SF,39,96
Stephen Curry,PG,35,95
Kevin Durant,PF,35,94
Giannis Antetokounmpo,PF,29,97
Nikola Jokic,C,29,98
```

### 4. Validações

O sistema valida automaticamente:

- ✅ **Nome**: Obrigatório, não pode ser vazio
- ✅ **Posição**: Obrigatória (PG, SG, SF, PF, C, etc.)
- ✅ **Idade**: Deve estar entre 18 e 50 anos
- ✅ **OVR**: Deve estar entre 40 e 99

### 5. Passo a Passo

1. **Selecione a Liga**: ELITE, NEXT, RISE ou ROOKIE
2. **Escolha o Ano**: Ano do draft (ex: 2024)
3. **Clique em "Confirmar Draft"**: O sistema vai buscar ou criar o draft
4. **Escolha o arquivo CSV**: Selecione seu arquivo .csv preparado
5. **Clique em "Importar Jogadores"**: Os jogadores serão importados

### 6. Template CSV

Na página de importação há um botão **"Baixar Template CSV"** que fornece um arquivo de exemplo pronto para usar.

## Criando CSV no Excel

### Opção 1: Salvar como CSV

1. Crie uma planilha com as colunas: `nome`, `posicao`, `idade`, `ovr`
2. Preencha os dados dos jogadores
3. Clique em **Arquivo → Salvar Como**
4. Escolha o formato **CSV (separado por vírgulas) (*.csv)**
5. Salve o arquivo

### Opção 2: Google Sheets

1. Crie uma planilha no Google Sheets
2. Preencha com as colunas e dados
3. Clique em **Arquivo → Fazer download → Valores separados por vírgula (.csv)**

## Exemplo Completo

```csv
nome,posicao,idade,ovr
LeBron James,SF,39,96
Stephen Curry,PG,35,95
Kevin Durant,PF,35,94
Giannis Antetokounmpo,PF,29,97
Nikola Jokic,C,29,98
Joel Embiid,C,30,96
Luka Doncic,PG,25,97
Jayson Tatum,SF,26,95
Shai Gilgeous-Alexander,PG,26,94
Anthony Davis,PF,31,94
```

## Mensagens de Erro Comuns

### "Linha X: Nome é obrigatório"
- Há uma linha com o campo nome vazio
- Verifique se todas as linhas têm nome preenchido

### "Linha X: Idade inválida"
- A idade está fora do intervalo 18-50
- Verifique se digitou a idade corretamente

### "Linha X: OVR inválido"
- O OVR está fora do intervalo 40-99
- Verifique se digitou o overall corretamente

### "Nenhum jogador válido encontrado"
- O arquivo está vazio ou só tem cabeçalho
- Adicione pelo menos um jogador

## Dicas

✅ Use o template fornecido para evitar erros de formato
✅ Não use acentos nas colunas do cabeçalho
✅ Certifique-se de que não há linhas vazias entre os dados
✅ Verifique se salvou como CSV, não como XLSX
✅ O sistema importa múltiplos jogadores de uma vez

## Suporte

Em caso de dúvidas ou problemas, entre em contato com o administrador do sistema.
