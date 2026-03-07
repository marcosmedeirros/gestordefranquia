<?php
/**
 * Helpers para responsividade dos games em mobile.
 * Inclui aviso de rotação opcional e utilitário de canvas responsivo.
 */

if (!function_exists('render_mobile_orientation_guard')) {
    /**
     * Imprime CSS/JS para bloquear a tela em portrait quando o jogo precisa de landscape.
     */
    function render_mobile_orientation_guard(bool $requires_landscape = false, string $overlay_id = 'mobileGuardOverlay'): void
    {
        ?>
        <style>
            .responsive-canvas {
                width: 100%;
                max-width: 900px;
                height: auto !important;
            }
            .mobile-guard-overlay {
                position: fixed;
                inset: 0;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 24px;
                background: rgba(10, 11, 14, 0.92);
                z-index: 2000;
            }
            .mobile-guard-overlay .mobile-guard-box {
                width: 100%;
                max-width: 340px;
                background: #14161d;
                border: 1px solid #2d3040;
                border-radius: 16px;
                padding: 22px;
                color: #f8fafc;
                text-align: center;
                box-shadow: 0 14px 38px rgba(0, 0, 0, 0.45);
            }
            .mobile-guard-overlay .mobile-guard-icon {
                font-size: 42px;
                margin-bottom: 10px;
            }
            .mobile-guard-overlay .mobile-guard-text {
                color: #cbd5e1;
                font-size: 0.95rem;
            }
            body.mobile-guard-locked {
                overflow: hidden;
                touch-action: none;
            }
        </style>
        <div id="<?= htmlspecialchars($overlay_id) ?>" class="mobile-guard-overlay" aria-live="polite">
            <div class="mobile-guard-box">
                <div class="mobile-guard-icon">📱🔄</div>
                <h5 class="mb-2">Vire o celular</h5>
                <p class="mobile-guard-text mb-0">Este jogo fica melhor na horizontal.</p>
            </div>
        </div>
        <script>
            (() => {
                const requiresLandscape = <?= $requires_landscape ? 'true' : 'false' ?>;
                const overlay = document.getElementById('<?= $overlay_id ?>');
                if (!overlay) return;

                const toggleOverlay = () => {
                    const isMobile = window.matchMedia('(max-width: 768px)').matches;
                    const needsRotate = requiresLandscape && isMobile && window.innerWidth < window.innerHeight;
                    overlay.style.display = needsRotate ? 'flex' : 'none';
                    document.body.classList.toggle('mobile-guard-locked', needsRotate);
                };

                window.addEventListener('resize', toggleOverlay);
                window.addEventListener('orientationchange', toggleOverlay);
                toggleOverlay();
            })();
        </script>
        <?php
    }
}
