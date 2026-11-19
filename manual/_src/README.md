# Manual Teknisoft Contab - fontes

- `pages/*.html`: conteudo de cada pagina (HTML simples).
- `build.py`: gera `manual/*.html` com o layout comum, menu, indice por pagina e pager.
  Atalho para prints: `<shot id="31-classificacao" url="rota/da/app" cap="<b>Titulo.</b> texto"/>`
  (a imagem tem de existir em `manual/screenshots/<id>.jpg`).
- Regenerar: `python3 manual/_src/build.py`
- A ordem e os titulos das paginas estao na lista `PAGES` do `build.py`.

Os prints foram captados com Playwright sobre a instalacao local, com anonimizacao no DOM
(marca, nomes de empresas e pessoas, NIF, e-mails, segredos) e o PDF desfocado nos modais.
