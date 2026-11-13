# Fonts

Lege hier die variable Nunito-Schrift ab:

- `Nunito-VariableFont_wght.ttf` (Gewichtsachse 200–1000)

Diese Datei wird in `src/scss/_base.scss` per `@font-face` unter dem
stabilen Pfad `/fonts/Nunito-VariableFont_wght.ttf` eingebunden
(ausgeliefert über `public/`). `font-display: swap` sorgt dafür, dass
bei fehlender/verzögerter Schrift sofort die Ersatzschrift `sans-serif`
greift (Req 3.5).
