#!/bin/bash
#
# Instala o worker de WhatsApp como serviço no macOS.
#
# Rodar UMA vez, no Terminal, dentro da pasta do projeto:
#     bash bot/instalar-mac.sh
#
# Para remover depois:
#     launchctl bootout gui/$(id -u)/com.fba.whatsapp
#     rm ~/Library/LaunchAgents/com.fba.whatsapp.plist
#
# O que ele NÃO faz, e você precisa fazer à mão (ver o final da saída):
#   · impedir o Mac de dormir
#   · ligar o login automático, pra voltar sozinho depois de queda de energia
#   · subir a Evolution e parear o WhatsApp

set -euo pipefail

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LABEL="com.fba.whatsapp"
DESTINO="$HOME/Library/LaunchAgents/$LABEL.plist"

echo "── Worker de WhatsApp da FBA · instalação no macOS ──"
echo

# ── PHP ────────────────────────────────────────────────────────────────
PHP="$(command -v php || true)"
if [ -z "$PHP" ]; then
    echo "✗ PHP não encontrado."
    echo "  Instale com:  brew install php"
    exit 1
fi
echo "✓ PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

# curl é o que o worker usa pra falar com o site e com a Evolution
if ! "$PHP" -m | grep -qi '^curl$'; then
    echo "✗ A extensão curl do PHP não está ativa — o worker não consegue"
    echo "  falar com o site nem com a Evolution sem ela."
    exit 1
fi
echo "✓ extensão curl ativa"

# ── Configuração ───────────────────────────────────────────────────────
CONFIG="$AQUI/whatsapp-local.config.php"
if [ ! -f "$CONFIG" ]; then
    echo
    echo "✗ Falta $CONFIG"
    echo "  Gere com:  php bot/configurar.php"
    echo "  (ele pede a URL do site, o token do bot e os dados da Evolution)"
    exit 1
fi
echo "✓ configuração encontrada"

# ── Instala o agente ───────────────────────────────────────────────────
mkdir -p "$HOME/Library/LaunchAgents"

sed -e "s|__PHP__|$PHP|g" \
    -e "s|__WORKER__|$AQUI/whatsapp-local.php|g" \
    -e "s|__DIR__|$AQUI|g" \
    "$AQUI/com.fba.whatsapp.plist" > "$DESTINO"

# bootout antes de bootstrap: reinstalar por cima sem descarregar deixa o
# launchd com a versão antiga em memória e nada muda.
launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$DESTINO"
launchctl enable "gui/$(id -u)/$LABEL"

echo "✓ serviço instalado e iniciado"
echo

# ── Confere se subiu mesmo ─────────────────────────────────────────────
sleep 3
if launchctl print "gui/$(id -u)/$LABEL" >/dev/null 2>&1; then
    echo "✓ o launchd está com o serviço carregado"
    echo "  Ver estado:  launchctl print gui/$(id -u)/$LABEL | head -20"
    echo "  Ver log:     tail -f $AQUI/whatsapp-local.log"
else
    echo "✗ o serviço não aparece no launchd — veja o log em"
    echo "  $AQUI/whatsapp-local.log"
    exit 1
fi

# ── Impedir o Mac de dormir ────────────────────────────────────────────
# Dorme = bot parado. É a causa número um de "o bot sumiu", então vale
# pedir a senha por isto em vez de deixar como lição de casa.
echo
echo "O Mac precisa NUNCA dormir, senão o bot para junto."
read -r -p "Desligar a suspensão agora? (precisa da sua senha) [S/n] " RESP
if [[ ! "${RESP:-S}" =~ ^[Nn] ]]; then
    # sleep 0        → nunca suspende o sistema
    # disablesleep 1 → num MacBook, segue rodando de tampa fechada
    # displaysleep   → a TELA pode apagar à vontade; isso não para nada
    if sudo pmset -a sleep 0 disablesleep 1 displaysleep 10 2>/dev/null; then
        echo "✓ suspensão desligada (a tela ainda apaga, o que não afeta o bot)"
    else
        echo "⚠ não consegui aplicar — faça em Ajustes → Energia"
    fi
else
    echo "⚠ pulado. Enquanto o Mac dormir, o bot fica parado."
fi

cat <<'FIM'

───────────────────────────────────────────────────────────────
FALTAM DUAS COISAS QUE ESTE SCRIPT NÃO PODE FAZER SOZINHO
───────────────────────────────────────────────────────────────

1. LIGAR O LOGIN AUTOMÁTICO
   Ajustes → Usuários e Grupos → Iniciar sessão automaticamente.

   Isto é o que faz o bot voltar sozinho depois de uma queda de energia.
   Sem isso o Mac liga e PARA na tela de senha — e ali nem o LaunchAgent
   nem o colima rodam, porque os dois dependem de uma sessão aberta.

   Tela BLOQUEADA é diferente de tela de LOGIN: com a sessão aberta e a
   tela travada, tudo continua rodando normalmente.

2. SUBIR A EVOLUTION E PAREAR O WHATSAPP

   Use COLIMA, não o Docker Desktop. Os dois rodam o mesmo container, mas
   o Desktop instala um aplicativo com ícone no Dock e uma baleia fixa na
   barra de menus. O colima é só linha de comando: nada aparece.

       brew install colima docker docker-compose
       colima start
       brew services start colima          # volta sozinho depois de reiniciar

       cd bot/evolution
       EVO_API_KEY=<a mesma chave da configuração> docker compose up -d

   Depois abra http://localhost:8081/manager e leia o QR code com o
   celular. Trocar de máquina significa parear de novo, a menos que você
   copie o volume evolution_instances.

   Uma ressalva honesta: "não aparecer" aqui é não ter janela, ícone nem
   notificação. Quem abrir o Terminal e rodar `docker ps` continua vendo o
   container — como veria qualquer serviço do sistema. Não existe rodar
   sem existir.

───────────────────────────────────────────────────────────────
FIM
