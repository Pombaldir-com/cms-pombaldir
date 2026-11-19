#!/usr/bin/env python3
"""Gera o manual estatico Teknisoft Contab a partir de _src/pages/*.html.

Uso:  python3 manual/_src/build.py
Cada fragmento e HTML simples. Atalhos suportados:
  <shot id="31-classificacao" url="contabilidade/..." cap="<b>Titulo.</b> texto"/>
  <shot ... class="phone"/>
Os <h2> recebem id automatico e alimentam o indice da pagina.
"""
import html
import os
import re
import unicodedata

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "_src", "pages")
VERSION = "Edição 1.0 · novembro 2026"

# (slug, titulo, grupo, palavras-chave para a pesquisa)
PAGES = [
    ("index", "Apresentação", "Visão geral", "inicio mais valias capa"),
    ("introducao", "Nota introdutória", "Visão geral", "objetivo convencoes publico"),
    ("primeiros-passos", "Primeiros passos", "Manual do utilizador", "login dashboard navegacao telemovel pwa"),
    ("configuracao", "Parâmetros e configuração", "Manual do utilizador", "definicoes geral servicos erp ai modulos permissoes utilizadores departamentos"),
    ("multi-empresa", "Multi-empresa (tenants)", "Manual do utilizador", "tenant nif base de dados empresa base emp"),
    ("entidades", "Entidades e clientes", "Manual do utilizador", "empresas ficha fornecedores campos adicionais extranet admin"),
    ("contabilidade", "Contabilidade: do documento ao ERP", "Manual do utilizador", "upload qr ocr classificacao importacao lancamentos"),
    ("motor-classificacao", "Motor de classificação: regras e aprendizagem", "Manual do utilizador", "regras automaticas personalizadas aprendizagem historico modelos instrucoes correcao sugestao"),
    ("tarefas", "Tarefas de fecho", "Manual do utilizador", "confirmacao pasta apuramento iva saft dp iva"),
    ("efatura", "E-fatura", "Manual do utilizador", "at portal sincronizacao documentos"),
    ("assistente-ia", "Assistente IA", "Manual do utilizador", "ia chat memoria modo seguro openai"),
    ("colaboracao", "Atividades, agenda e equipa", "Manual do utilizador", "atividades calendario chat interno assiduidade ponto"),
    ("extranet", "Área reservada do cliente", "Manual do utilizador", "extranet cliente documentos saft impersonar"),
    ("integracoes", "Integrações e webservices", "Manual técnico", "eticadata primavera erp-sinc api at textract openai caldav push"),
    ("manual-tecnico", "Manual técnico", "Manual técnico", "arquitetura instalacao requisitos migracoes cron seguranca backups"),
    ("propostas", "Propostas de melhoria", "Roadmap", "roadmap melhorias futuro"),
]


def slugify(text):
    t = unicodedata.normalize("NFD", text)
    t = "".join(c for c in t if unicodedata.category(c) != "Mn")
    t = re.sub(r"<[^>]+>", "", t).lower()
    return re.sub(r"[^a-z0-9]+", "-", t).strip("-")[:60]


def expand_shots(body):
    def repl(m):
        attrs = dict(re.findall(r'(\w+)="([^"]*)"', m.group(1)))
        sid = attrs["id"]
        url = attrs.get("url", "")
        cap = attrs.get("cap", "")
        cls = attrs.get("class", "")
        alt = re.sub(r"<[^>]+>", "", cap) or sid
        chrome = ""
        if "phone" not in cls:
            chrome = ('<div class="chrome"><i></i><i></i><i></i><span>app.teknisoftcontab.pt/'
                      + html.escape(url) + "</span></div>")
        return (f'<figure class="shot {cls}">{chrome}'
                f'<img src="screenshots/{sid}.jpg" alt="{html.escape(alt)}" loading="lazy">'
                + (f"<figcaption>{cap}</figcaption>" if cap else "") + "</figure>")
    return re.sub(r'<shot((?:\s+\w+="[^"]*")*)\s*/?>', repl, body)


def add_heading_ids(body):
    toc = []

    def repl(m):
        attrs, inner = m.group(1) or "", m.group(2)
        if "id=" in attrs:
            hid = re.search(r'id="([^"]+)"', attrs).group(1)
        else:
            hid = slugify(inner)
            attrs = f' id="{hid}"' + attrs
        if "notoc" not in attrs:
            toc.append((hid, re.sub(r"<[^>]+>", "", inner)))
        return f"<h2{attrs.replace(' notoc', '')}>{inner}</h2>"
    body = re.sub(r"<h2([^>]*)>(.*?)</h2>", repl, body, flags=re.S)
    return body, toc


def nav_html(current):
    out, group = [], None
    for i, (slug, title, grp, kw) in enumerate(PAGES):
        if grp != group:
            out.append(f'<div class="group">{grp}</div>')
            group = grp
        cls = ' class="active"' if slug == current else ""
        out.append(f'<a href="{slug}.html"{cls} data-k="{kw}"><span class="n">{i:02d}</span>{title}</a>')
    return "\n".join(out)


TEMPLATE = """<!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} · Teknisoft Contab</title>
<meta name="description" content="Manual de apresentação, utilizador e técnico do Teknisoft Contab.">
<link rel="icon" href="assets/img/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/manual.css">
</head>
<body>
<div class="layout">
  <aside class="side">
    <div class="brand"><img src="assets/img/icon.svg" alt=""><div><b>Teknisoft Contab</b><small>Manual</small></div></div>
    <div class="search"><input type="search" placeholder="Pesquisar no manual..." aria-label="Pesquisar"></div>
    <nav>
{nav}
    </nav>
  </aside>
  <div class="main">
    <div class="topbar">
      <button class="menu-btn" aria-label="Menu">&#9776;</button>
      <div class="crumb">{group} &rsaquo; <b>{title}</b></div>
      <div class="spacer"></div>
      <button class="print-btn" type="button">Imprimir / PDF</button>
    </div>
    <main class="content">
{body}
      <div class="pager">{prev}{next}</div>
      <div class="foot">Teknisoft Contab · {version} · Imagens com dados de demonstração anonimizados.</div>
    </main>
  </div>
</div>
<script src="assets/js/manual.js"></script>
</body>
</html>
"""


def build():
    for i, (slug, title, grp, _kw) in enumerate(PAGES):
        src = os.path.join(SRC, slug + ".html")
        with open(src, encoding="utf-8") as fh:
            body = fh.read()
        body = expand_shots(body)
        body, toc = add_heading_ids(body)
        if "<!--toc-->" in body and toc:
            items = "".join(f'<li><a href="#{h}">{t}</a></li>' for h, t in toc)
            body = body.replace("<!--toc-->", f'<div class="toc"><b>Nesta página</b><ol>{items}</ol></div>')
        prev = nxt = "<span></span>"
        if i > 0:
            p = PAGES[i - 1]
            prev = f'<a class="prev" href="{p[0]}.html"><small>&larr; Anterior</small>{p[1]}</a>'
        if i < len(PAGES) - 1:
            n = PAGES[i + 1]
            nxt = f'<a class="next" href="{n[0]}.html"><small>Seguinte &rarr;</small>{n[1]}</a>'
        out = TEMPLATE.format(title=title, group=grp, nav=nav_html(slug), body=body,
                              prev=prev, next=nxt, version=VERSION)
        with open(os.path.join(ROOT, slug + ".html"), "w", encoding="utf-8") as fh:
            fh.write(out)
        print("ok", slug)


if __name__ == "__main__":
    build()
