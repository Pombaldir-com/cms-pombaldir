#!/usr/bin/env python3
import argparse
import hashlib
import html
import json
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlencode, urljoin

import requests

CURRENT_ARTIFACT_PATH = None


def load_dotenv(root_path):
    env_path = Path(root_path) / ".env"
    if not env_path.is_file():
        return
    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip("'").strip('"')
        os.environ.setdefault(key, value)


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def write_artifact(path, payload):
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(payload, ensure_ascii=True, indent=2), encoding="utf-8")


def update_artifact(path, updater):
    target = Path(path)
    current = {}
    if target.is_file():
        try:
            current = json.loads(target.read_text(encoding="utf-8"))
        except Exception:
            current = {}
    current = updater(current) or current
    write_artifact(path, current)


def mask_username(value):
    value = (value or "").strip()
    if len(value) <= 4:
        return "*" * len(value)
    return value[:2] + ("*" * (len(value) - 4)) + value[-2:]


def env_value(name, default=""):
    return os.environ.get(name, default).strip()


def env_list(name, default_values):
    raw = os.environ.get(name, "")
    if not raw.strip():
        return list(default_values)
    return [part.strip() for part in raw.split("||") if part.strip()]


def parse_args():
    parser = argparse.ArgumentParser(description="Sincronizacao E-fatura via browser automation.")
    parser.add_argument("--job-id", required=True)
    parser.add_argument("--artifact", required=True)
    parser.add_argument("--company-name", default="")
    parser.add_argument("--company-vat", default="")
    parser.add_argument("--portal-username", default="")
    parser.add_argument("--period-start", required=True)
    parser.add_argument("--period-end", required=True)
    return parser.parse_args()


def main():
    root_path = Path(__file__).resolve().parents[1]
    load_dotenv(root_path)
    args = parse_args()

    artifact_payload = {
        "job_id": int(args.job_id),
        "status": "running",
        "company_name": args.company_name,
        "company_vat": args.company_vat,
        "portal_username_mask": mask_username(args.portal_username),
        "period_start": args.period_start,
        "period_end": args.period_end,
        "started_at": utc_now(),
        "documents_found": 0,
        "documents_saved": 0,
        "debug": {"steps": []},
    }
    write_artifact(args.artifact, artifact_payload)
    global CURRENT_ARTIFACT_PATH
    CURRENT_ARTIFACT_PATH = args.artifact

    try:
        documents, debug = sync_documents(args)

        update_artifact(
            args.artifact,
            lambda current: {
                **current,
                "status": "done",
                "finished_at": utc_now(),
                "documents_found": len(documents),
                "documents_saved": 0,
                "documents": documents,
                "debug": debug,
                "error_message": "",
            },
        )
        return 0
    except Exception as exc:
        update_artifact(
            args.artifact,
            lambda current: {
                **current,
                "status": "failed",
                "finished_at": utc_now(),
                "error_message": str(exc),
            },
        )
        return 1


def sync_documents(args):
    login_url = env_value(
        "EFATURA_LOGIN_URL",
        "https://www.acesso.gov.pt/jsp/loginRedirectForm.jsp?path=consultarDocumentosAdquirente.action&partID=EFPF",
    )
    home_url = env_value("EFATURA_HOME_URL", "https://faturas.portaldasfinancas.gov.pt/home.action")
    portal_url = env_value("EFATURA_PORTAL_URL", "https://faturas.portaldasfinancas.gov.pt/painelAdquirente.action")
    consulta_url = env_value("EFATURA_CONSULTA_URL", "https://faturas.portaldasfinancas.gov.pt/consultarDocumentosAdquirente.action")
    consulta_json_url = env_value("EFATURA_CONSULTA_JSON_URL", "https://faturas.portaldasfinancas.gov.pt/json/obterDocumentosAdquirente.action")
    password = env_value("EFATURA_PORTAL_PASSWORD", "")
    if not password:
        raise RuntimeError("EFATURA_PORTAL_PASSWORD nao configurada no ambiente/.env do worker.")

    selectors = {
        "nif_tab": env_list("EFATURA_NIF_TAB_SELECTORS", ['button[role="tab"]', '[role="tab"]']),
        "username": env_list("EFATURA_USERNAME_SELECTORS", ["#username", 'input[name="username"]', 'input[type="text"]']),
        "password": env_list("EFATURA_PASSWORD_SELECTORS", ["#password", 'input[name="password"]', 'input[type="password"]']),
        "submit": env_list("EFATURA_SUBMIT_SELECTORS", ['button[type="submit"]', 'input[type="submit"]']),
        "date_from": env_list("EFATURA_DATE_FROM_SELECTORS", ['input[name*="start"]', 'input[id*="start"]', 'input[type="date"]']),
        "date_to": env_list("EFATURA_DATE_TO_SELECTORS", ['input[name*="end"]', 'input[id*="end"]', 'input[type="date"]']),
        "search": env_list("EFATURA_SEARCH_SELECTORS", ['button[type="submit"]', 'button', 'input[type="submit"]']),
        "rows": env_list("EFATURA_ROW_SELECTORS", ['table tbody tr', '.table tbody tr', '[role="rowgroup"] [role="row"]']),
    }
    debug = {"steps": []}
    allow_html_fallback = env_value("EFATURA_ALLOW_HTML_FALLBACK", "0") == "1"

    http_result = sync_documents_via_http(
        login_url,
        portal_url,
        consulta_url,
        consulta_json_url,
        args.portal_username,
        password,
        args.period_start,
        args.period_end,
        args.company_vat,
        debug,
    )
    if http_result["state"] == "done":
        return http_result["documents"], debug
    if http_result["state"] == "fallback":
        step(debug, "Fluxo HTTP indisponivel; a tentar fallback browser")
    else:
        step(debug, "Fluxo HTTP sem documentos; a terminar sem fallback")
        return [], debug

    return sync_documents_via_browser(
        login_url,
        home_url,
        portal_url,
        consulta_url,
        consulta_json_url,
        args,
        password,
        selectors,
        debug,
        allow_html_fallback,
    )


def sync_documents_via_http(login_url, portal_url, consulta_url, consulta_json_url, username, password, period_start, period_end, company_vat, debug):
    session = requests.Session()
    session.headers.update({
        "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0 Safari/537.36",
    })

    step(debug, f"HTTP login page: {login_url}")
    login_page = session.get(login_url, timeout=30)
    login_page.raise_for_status()

    login_config = extract_login_page_config(login_page.text)
    if not login_config.get("csrf_token") or not login_config.get("submit_url"):
        step(debug, "Fluxo HTTP sem configuracao de login suficiente; a usar fallback browser")
        return {"state": "fallback", "documents": []}

    login_submit_url = env_value("EFATURA_LOGIN_SUBMIT_URL", login_config["submit_url"])
    post_data = {
        "username": username,
        "password": password,
        login_config["csrf_name"]: login_config["csrf_token"],
        "selectedAuthMethod": "N",
        "authVersion": login_config["auth_version"] or "1",
    }
    for key, value in (login_config.get("attributes") or {}).items():
        if str(key).strip() != "":
            post_data[str(key)] = "" if value is None else str(value)
    step(debug, f"HTTP submit: {login_submit_url}")
    login_response = session.post(
        login_submit_url,
        data=post_data,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        timeout=30,
    )
    login_response.raise_for_status()

    relay_fields = extract_hidden_inputs(login_response.text, ["sign", "userID", "sessionID", "nif", "tc", "tv", "userName", "partID"])
    if not relay_fields.get("sign"):
        step(debug, "Fluxo HTTP sem campos de relay; a usar fallback browser")
        debug["http_login_sample"] = login_response.text[:1500]
        return {"state": "fallback", "documents": []}

    target_post = extract_form_action(login_response.text) or portal_url or "https://faturas.portaldasfinancas.gov.pt/painelAdquirente.action"
    step(debug, f"HTTP relay para painel: {target_post}")
    relay_response = session.post(target_post, data=relay_fields, timeout=30)
    relay_response.raise_for_status()

    if consulta_url:
        step(debug, f"HTTP abrir consulta: {consulta_url}")
        consulta_response = session.get(consulta_url, timeout=30)
        consulta_response.raise_for_status()

    query = {
        "dataInicioFilter": period_start,
        "dataFimFilter": period_end,
        "ambitoAquisicaoFilter": "TODOS",
    }
    target = consulta_json_url + ("&" if "?" in consulta_json_url else "?") + urlencode(query)
    step(debug, f"HTTP JSON autenticado: {target}")
    json_response = session.get(target, timeout=30)
    json_response.raise_for_status()
    try:
        payload = json_response.json()
    except Exception:
        debug["http_json_raw"] = json_response.text[:2000]
        raise RuntimeError("Resposta JSON invalida no endpoint do E-fatura.")
    debug["http_json_keys"] = list(payload.keys())[:20] if isinstance(payload, dict) else []

    if isinstance(payload, dict) and payload.get("expiredSession") is True:
        raise RuntimeError("Sessao E-fatura expirada no endpoint JSON autenticado.")
    if isinstance(payload, dict) and payload.get("success") is False:
        debug["http_json_sample"] = json.dumps(payload, ensure_ascii=False)[:2000]
        return {"state": "fallback", "documents": []}

    rows = extract_rows_from_json_payload(payload if isinstance(payload, dict) else {})
    step(debug, f"HTTP JSON devolveu {len(rows)} linha(s) candidata(s)")
    documents = []
    for row in rows:
        document = normalize_json_row(row, company_vat)
        if document:
            documents.append(document)
    if not documents and isinstance(payload, dict):
        debug["http_json_sample"] = json.dumps(payload, ensure_ascii=False)[:2000]
    return {"state": "done", "documents": documents}


def sync_documents_via_browser(login_url, home_url, portal_url, consulta_url, consulta_json_url, args, password, selectors, debug, allow_html_fallback):
    try:
        from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
        from playwright.sync_api import sync_playwright
    except Exception as exc:
        raise RuntimeError(
            "A dependencia Python 'playwright' nao esta instalada. Instala com: pip install playwright && python -m playwright install chromium"
        ) from exc

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=env_value("EFATURA_HEADLESS", "1") != "0")
        context = browser.new_context(accept_downloads=True, locale="pt-PT")
        page = context.new_page()
        page.set_default_timeout(20000)
        page.set_default_navigation_timeout(30000)

        try:
            step(debug, f"A abrir login browser: {login_url}")
            page.goto(login_url, wait_until="domcontentloaded", timeout=30000)
            open_nif_login_tab(page, debug, selectors["nif_tab"])
            fill_first(page, selectors["username"], args.portal_username)
            fill_first(page, selectors["password"], password)
            step(debug, "Credenciais preenchidas no browser")
            click_login(page, selectors["submit"])
            wait_after_login(page, debug)

            maybe_dismiss_prompts(page, debug)
            submit_browser_relay_if_present(page, debug)
            ensure_efatura_session(page, home_url, portal_url, debug)
            record_session_diagnostics(context, page, debug, "pre_consulta")

            if consulta_url:
                step(debug, f"A abrir consulta configurada: {consulta_url}")
                page.goto(consulta_url, wait_until="domcontentloaded", timeout=30000)
                page.wait_for_timeout(2000)
                log_page_state(page, debug, "apos_consulta")
                record_session_diagnostics(context, page, debug, "apos_consulta")
            else:
                step(debug, "A abrir painel adquirente")
                page.goto(portal_url, wait_until="domcontentloaded", timeout=30000)
                page.wait_for_timeout(2000)
                open_efatura_area(page, debug)
                log_page_state(page, debug, "apos_painel")
                record_session_diagnostics(context, page, debug, "apos_painel")

            documents = fetch_documents_via_json(
                context,
                page,
                home_url,
                portal_url,
                consulta_url,
                consulta_json_url,
                args.period_start,
                args.period_end,
                args.company_vat,
                debug,
            )
            if not documents and allow_html_fallback:
                step(debug, "JSON browser sem documentos utilizaveis; a tentar fallback HTML")
                apply_period_filters(page, args.period_start, args.period_end, selectors, debug, PlaywrightTimeoutError)
                documents = extract_documents(page, args.company_vat, debug, selectors["rows"])
            if not documents:
                save_debug_snapshot(page, args.artifact, debug)
                raise RuntimeError("Nao foi possivel obter um payload JSON valido do E-fatura. O fallback HTML esta desativado para evitar sincronizar paginas erradas.")

            return documents, debug
        finally:
            context.close()
            browser.close()


def step(debug, message):
    debug.setdefault("steps", []).append({"at": utc_now(), "message": message})
    if CURRENT_ARTIFACT_PATH:
        update_artifact(
            CURRENT_ARTIFACT_PATH,
            lambda current: {
                **current,
                "last_step": message,
                "debug": debug,
            },
        )


def fill_first(page, selector_list, value):
    last_error = None
    for selector in selector_list:
        try:
            locator = page.locator(selector).first
            locator.wait_for(state="visible", timeout=4000)
            locator.fill(value)
            return
        except Exception as exc:
            last_error = exc
    raise RuntimeError(f"Campo nao encontrado para seletores: {selector_list}") from last_error


def open_nif_login_tab(page, debug, selector_list):
    try:
        page.get_by_role("tab", name=re.compile(r"^NIF$", re.I)).first.click(timeout=5000)
        page.wait_for_timeout(800)
        step(debug, "Tab NIF selecionado via role=tab")
        return
    except Exception:
        pass

    for selector in selector_list:
        try:
            locator = page.locator(selector).filter(has_text=re.compile(r"^NIF$", re.I)).first
            if locator.count() == 0:
                continue
            locator.click(timeout=4000)
            page.wait_for_timeout(800)
            step(debug, f"Tab NIF selecionado via seletor {selector}")
            return
        except Exception:
            continue

    step(debug, "Nao foi necessario ou possivel selecionar explicitamente o tab NIF")


def click_login(page, selector_list):
    for selector in selector_list:
        try:
            locator = page.locator(selector).first
            if locator.count() == 0:
                continue
            locator.click()
            return
        except Exception:
            continue
    for text in ["Autenticar", "Entrar", "Login", "Iniciar sessao"]:
        try:
            page.get_by_role("button", name=re.compile(text, re.I)).click()
            return
        except Exception:
            continue
    raise RuntimeError("Botao de login nao encontrado.")


def wait_after_login(page, debug):
    try:
        page.wait_for_load_state("domcontentloaded", timeout=15000)
    except Exception:
        pass
    page.wait_for_timeout(3500)
    step(debug, "Login submetido")
    log_page_state(page, debug, "apos_login")
    capture_post_login_diagnostics(page, debug, "apos_login")
    detect_login_blockers(page, debug, "apos_login")


def maybe_dismiss_prompts(page, debug):
    for text in ["Aceitar", "Continuar", "Fechar", "OK"]:
        try:
            page.get_by_role("button", name=re.compile(text, re.I)).click(timeout=1500)
            step(debug, f"Prompt fechado: {text}")
        except Exception:
            pass


def open_efatura_area(page, debug):
    patterns = [r"e-?fatura", r"faturas", r"fatura"]
    for pattern in patterns:
        for role in ("link", "button"):
            try:
                page.get_by_role(role, name=re.compile(pattern, re.I)).first.click(timeout=4000)
                page.wait_for_timeout(2000)
                step(debug, f"Area E-fatura aberta via {role}:{pattern}")
                return
            except Exception:
                continue
    step(debug, "Nenhum atalho automatico de e-fatura encontrado; segue na pagina atual")


def submit_browser_relay_if_present(page, debug):
    try:
        relay_info = page.evaluate(
            """() => {
                const forms = Array.from(document.querySelectorAll('form'));
                for (const form of forms) {
                    const hiddenNames = Array.from(form.querySelectorAll('input[type="hidden"]'))
                        .map((input) => (input.getAttribute('name') || '').trim())
                        .filter(Boolean);
                    const hasRelayFields = ['sign', 'sessionID', 'userID'].every((name) => hiddenNames.includes(name));
                    if (!hasRelayFields) {
                        continue;
                    }
                    return {
                        action: form.getAttribute('action') || '',
                        method: form.getAttribute('method') || 'get',
                        hidden_names: hiddenNames
                    };
                }
                return null;
            }"""
        )
    except Exception as exc:
        step(debug, f"Falha a detetar relay browser: {exc}")
        return

    if not relay_info:
        return

    debug["browser_relay_form"] = relay_info
    try:
        submitted = page.evaluate(
            """() => {
                const forms = Array.from(document.querySelectorAll('form'));
                for (const form of forms) {
                    const hiddenNames = Array.from(form.querySelectorAll('input[type="hidden"]'))
                        .map((input) => (input.getAttribute('name') || '').trim())
                        .filter(Boolean);
                    const hasRelayFields = ['sign', 'sessionID', 'userID'].every((name) => hiddenNames.includes(name));
                    if (!hasRelayFields) {
                        continue;
                    }
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                    return true;
                }
                return false;
            }"""
        )
        if submitted:
            step(debug, "Formulario relay submetido no browser")
            try:
                page.wait_for_load_state("domcontentloaded", timeout=15000)
            except Exception:
                pass
            page.wait_for_timeout(2000)
            log_page_state(page, debug, "apos_browser_relay")
            capture_post_login_diagnostics(page, debug, "apos_browser_relay")
    except Exception as exc:
        step(debug, f"Falha a submeter relay browser: {exc}")


def ensure_efatura_session(page, home_url, portal_url, debug):
    targets = [home_url, portal_url]
    for idx, target in enumerate([item for item in targets if item]):
        try:
            step(debug, f"A inicializar sessao E-fatura: {target}")
            page.goto(target, wait_until="domcontentloaded", timeout=30000)
            page.wait_for_timeout(2000)
            maybe_dismiss_prompts(page, debug)
            log_page_state(page, debug, f"session_init_{idx + 1}")
            if is_efatura_authenticated_page(page):
                return
        except Exception as exc:
            step(debug, f"Falha ao abrir {target}: {exc}")


def is_efatura_authenticated_page(page):
    current_url = (page.url or "").lower()
    if "acesso.gov.pt" in current_url:
        return False
    if "faturas.portaldasfinancas.gov.pt" in current_url:
        return True
    return False


def log_page_state(page, debug, key_prefix):
    debug[f"{key_prefix}_url"] = page.url
    try:
        debug[f"{key_prefix}_title"] = page.title()
    except Exception:
        pass


def record_session_diagnostics(context, page, debug, key_prefix):
    log_page_state(page, debug, key_prefix)
    try:
        cookies = context.cookies()
    except Exception as exc:
        debug[f"{key_prefix}_cookies_error"] = str(exc)
        return

    domains = {}
    for cookie in cookies:
        domain = clean_text(cookie.get("domain")).lstrip(".") or "(sem-dominio)"
        domains.setdefault(domain, []).append({
            "name": clean_text(cookie.get("name")),
            "path": clean_text(cookie.get("path")) or "/",
            "secure": bool(cookie.get("secure")),
            "http_only": bool(cookie.get("httpOnly")),
            "same_site": clean_text(cookie.get("sameSite")) or "",
        })

    for domain in domains.values():
        domain.sort(key=lambda item: (item["name"], item["path"]))

    debug[f"{key_prefix}_cookie_domains"] = sorted(domains.keys())
    debug[f"{key_prefix}_cookies_by_domain"] = domains


def summarize_session_diagnostics(debug, key_prefix):
    current_url = clean_text(debug.get(f"{key_prefix}_url"))
    domains = debug.get(f"{key_prefix}_cookie_domains") or []
    if not current_url and not domains:
        return ""
    return f"url={current_url or '-'} | cookies={','.join(domains) if domains else '-'}"


def capture_post_login_diagnostics(page, debug, key_prefix):
    log_page_state(page, debug, key_prefix)
    try:
        hidden_inputs = page.locator('input[type="hidden"]').evaluate_all(
            """(nodes) => nodes.map((node) => ({
                name: node.getAttribute('name') || '',
                id: node.getAttribute('id') || '',
                value_length: (node.value || '').length
            }))"""
        )
        debug[f"{key_prefix}_hidden_inputs"] = hidden_inputs[:80]
    except Exception as exc:
        debug[f"{key_prefix}_hidden_inputs_error"] = str(exc)

    try:
        forms = page.locator("form").evaluate_all(
            """(nodes) => nodes.map((node) => ({
                action: node.getAttribute('action') || '',
                method: node.getAttribute('method') || '',
                hidden_count: node.querySelectorAll('input[type="hidden"]').length
            }))"""
        )
        debug[f"{key_prefix}_forms"] = forms[:20]
    except Exception as exc:
        debug[f"{key_prefix}_forms_error"] = str(exc)

    try:
        body_text = extract_page_text_payload(page)
        debug[f"{key_prefix}_text_sample"] = clean_text(body_text)[:2000]
    except Exception as exc:
        debug[f"{key_prefix}_text_sample_error"] = str(exc)


def detect_login_blockers(page, debug, key_prefix):
    current_url = clean_text(page.url).lower()
    text_sample = clean_text(debug.get(f"{key_prefix}_text_sample"))
    normalized_text = html.unescape(text_sample).lower()

    if "numero de tentativas de login excedido" in normalized_text and "credencial temporariamente suspensa" in normalized_text:
        raise RuntimeError("Credencial E-fatura temporariamente suspensa por excesso de tentativas de login.")

    if "credencial temporariamente suspensa" in normalized_text:
        raise RuntimeError("Credencial E-fatura temporariamente suspensa.")

    if "submissaoformulariologin" in current_url and "autenticar" in normalized_text and "erro" in normalized_text:
        step(debug, "Login permaneceu na pagina de autenticacao apos submissao")


def apply_period_filters(page, period_start, period_end, selectors, debug, playwright_timeout):
    filled = False
    for selector in selectors["date_from"]:
        try:
            page.locator(selector).first.fill(period_start)
            filled = True
            break
        except Exception:
            continue
    for selector in selectors["date_to"]:
        try:
            page.locator(selector).nth(0).fill(period_end)
            filled = True
            break
        except Exception:
            continue

    if filled:
        step(debug, f"Periodo aplicado: {period_start} -> {period_end}")
        for selector in selectors["search"]:
            try:
                page.locator(selector).first.click()
                page.wait_for_timeout(2000)
                return
            except Exception:
                continue
        for text in ["Pesquisar", "Consultar", "Procurar", "Aplicar"]:
            try:
                page.get_by_role("button", name=re.compile(text, re.I)).click(timeout=4000)
                page.wait_for_timeout(2000)
                return
            except playwright_timeout:
                continue
            except Exception:
                continue
    else:
        step(debug, "Filtros de periodo nao encontrados; a tentar extrair a vista atual")


def fetch_documents_via_json(context, page, home_url, portal_url, consulta_url, endpoint, period_start, period_end, company_vat, debug):
    query = {
        "dataInicioFilter": period_start,
        "dataFimFilter": period_end,
        "ambitoAquisicaoFilter": "TODOS",
    }
    target = endpoint + ("&" if "?" in endpoint else "?") + urlencode(query)
    step(debug, f"A consultar JSON autenticado: {target}")
    for attempt in range(2):
        if attempt > 0:
            step(debug, "Sessao JSON expirada; a reabrir a area E-fatura antes de repetir")
            ensure_efatura_session(page, home_url, portal_url, debug)
            record_session_diagnostics(context, page, debug, f"json_retry_{attempt}_session")
            if consulta_url:
                try:
                    page.goto(consulta_url, wait_until="domcontentloaded", timeout=30000)
                    page.wait_for_timeout(2000)
                    log_page_state(page, debug, f"json_retry_{attempt}_consulta")
                    record_session_diagnostics(context, page, debug, f"json_retry_{attempt}_consulta")
                except Exception as exc:
                    step(debug, f"Falha ao reabrir consulta antes do retry JSON: {exc}")

        json_payload, raw_body = fetch_json_payload_via_browser_page(context, target, page.url, debug)
        if json_payload is None:
            json_payload, raw_body = fetch_json_payload_via_request(context, target, page.url, debug)
        if json_payload is None:
            return []
        if isinstance(json_payload, dict):
            debug["json_fetch_keys"] = list(json_payload.keys())[:20]
        if not isinstance(json_payload, dict):
            debug["json_raw_sample"] = raw_body[:1000]
            return []

        if json_payload.get("expiredSession") is True:
            debug["json_raw_sample"] = json.dumps(json_payload, ensure_ascii=False)[:2000]
            record_session_diagnostics(context, page, debug, f"json_expired_attempt_{attempt + 1}")
            session_summary = summarize_session_diagnostics(debug, f"json_expired_attempt_{attempt + 1}")
            if session_summary:
                step(debug, f"Diagnostico de sessao #{attempt + 1}: {session_summary}")
            if attempt == 0:
                continue
            raise RuntimeError("Sessao E-fatura expirada logo apos o login.")
        if json_payload.get("success") is False:
            debug["json_raw_sample"] = json.dumps(json_payload, ensure_ascii=False)[:2000]
            return []

        rows = extract_rows_from_json_payload(json_payload)
        step(debug, f"JSON devolveu {len(rows)} linha(s) candidata(s)")
        if not rows:
            debug["json_raw_sample"] = json.dumps(json_payload, ensure_ascii=False)[:2000]
            return []

        documents = []
        for row in rows:
            document = normalize_json_row(row, company_vat)
            if document:
                documents.append(document)
        if not documents:
            debug["json_raw_sample"] = json.dumps(json_payload, ensure_ascii=False)[:2000]
        return documents

    return []


def fetch_json_payload_via_browser_page(context, target, referer_url, debug):
    json_page = None
    try:
        json_page = context.new_page()
        json_page.set_default_timeout(20000)
        json_page.set_default_navigation_timeout(30000)
        response = json_page.goto(target, wait_until="domcontentloaded", timeout=30000, referer=referer_url or None)
        debug["json_browser_url"] = json_page.url
        try:
            debug["json_browser_title"] = json_page.title()
        except Exception:
            pass
        if response is not None:
            debug["json_fetch_status"] = response.status
            if not response.ok:
                step(debug, f"JSON browser devolveu status HTTP {response.status}")
                return None, ""
        raw_body = extract_page_text_payload(json_page)
        return parse_json_payload(raw_body, debug, "json_browser"), raw_body
    except Exception as exc:
        step(debug, f"Falha no browser JSON autenticado: {exc}")
        return None, ""
    finally:
        if json_page is not None:
            try:
                json_page.close()
            except Exception:
                pass


def fetch_json_payload_via_request(context, target, referer_url, debug):
    try:
        response = context.request.get(
            target,
            headers={
                "Accept": "application/json, text/javascript, */*; q=0.01",
                "Referer": referer_url,
                "X-Requested-With": "XMLHttpRequest",
            },
        )
    except Exception as exc:
        step(debug, f"Falha no request JSON autenticado: {exc}")
        return None, ""

    debug["json_request_url"] = target
    debug["json_request_page_url"] = referer_url
    debug["json_request_status"] = response.status
    if not response.ok:
        step(debug, f"JSON request devolveu status HTTP {response.status}")
        return None, ""

    raw_body = response.text()
    return parse_json_payload(raw_body, debug, "json_request"), raw_body


def parse_json_payload(raw_body, debug, key_prefix):
    try:
        return json.loads(raw_body)
    except Exception as exc:
        debug[f"{key_prefix}_parse_error"] = str(exc)
        debug["json_raw_sample"] = (raw_body or "")[:1000]
        return None


def extract_page_text_payload(page):
    try:
        body_text = page.locator("body").inner_text(timeout=5000)
    except Exception:
        body_text = ""
    if body_text.strip():
        return body_text.strip()
    html_content = page.content()
    pre_match = re.search(r"<pre[^>]*>(.*?)</pre>", html_content, re.S | re.I)
    if pre_match:
        return html.unescape(pre_match.group(1)).strip()
    return html_content.strip()


def extract_hidden_input(html, name):
    pattern = r'name="' + re.escape(name) + r'"\s+value="([^"]*)"'
    match = re.search(pattern, html, re.I)
    return match.group(1) if match else ""


def extract_hidden_inputs(html, names):
    result = {}
    for name in names:
        result[name] = extract_hidden_input(html, name)
    return result


def extract_form_action(html_text):
    match = re.search(r'<form[^>]+action="([^"]+)"', html_text, re.I)
    return match.group(1).strip() if match else ""


def extract_login_page_config(html_text):
    attributes = {}
    data_attributes_match = re.search(r'<script id="data-attributes" type="application/json">(.+?)</script>', html_text, re.S | re.I)
    if data_attributes_match:
        try:
            attributes = json.loads(data_attributes_match.group(1).strip())
        except Exception:
            attributes = {}

    submit_url_match = re.search(r"urlLogin:\s*stringOrNull\('([^']*)'\)", html_text)
    csrf_name_match = re.search(r"parameterName:\s*`([^`]+)`", html_text)
    csrf_token_match = re.search(r"token:\s*`([^`]+)`", html_text)
    auth_version_match = re.search(r"authVersion:\s*stringOrNull\('([^']*)'\)", html_text)
    submit_url = submit_url_match.group(1).strip() if submit_url_match else ""

    return {
        "submit_url": urljoin("https://www.acesso.gov.pt/jsp/", submit_url) if submit_url else "",
        "csrf_name": csrf_name_match.group(1).strip() if csrf_name_match else "_csrf",
        "csrf_token": csrf_token_match.group(1).strip() if csrf_token_match else "",
        "auth_version": auth_version_match.group(1).strip() if auth_version_match else "1",
        "attributes": attributes,
    }


def extract_rows_from_json_payload(payload):
    candidate_keys = ["linhas", "aaData", "data", "rows", "documentos", "documents", "listaDocumentos", "lista"]
    seen = []
    if isinstance(payload, dict):
        for key in candidate_keys:
            value = payload.get(key)
            if isinstance(value, list):
                seen.extend(value)
        for value in payload.values():
            if isinstance(value, dict):
                seen.extend(extract_rows_from_json_payload(value))
    return seen


def normalize_json_row(row, company_vat):
    if isinstance(row, list):
        return normalize_row([], [clean_text(value) for value in row], company_vat)
    if not isinstance(row, dict):
        return None

    if "idDocumento" in row or "numerodocumento" in row or "nomeEmitente" in row:
        invoice_no = clean_text(row.get("numerodocumento"))
        invoice_date = normalize_date(clean_text(row.get("dataEmissaoDocumento")))
        issuer_vat = extract_nif(clean_text(row.get("nifEmitente")))
        source_basis = "|".join([
            company_vat or "",
            issuer_vat,
            invoice_no,
            invoice_date,
            clean_text(row.get("idDocumento")),
        ])
        if not invoice_no or not invoice_date:
            return None
        return {
            "issuer_vat": issuer_vat,
            "issuer_name": clean_text(row.get("nomeEmitente")),
            "customer_vat": extract_nif(clean_text(row.get("nifAdquirente"))) or (company_vat or ""),
            "invoice_no": invoice_no,
            "atcud": clean_text(row.get("atcud")),
            "invoice_date": invoice_date,
            "invoice_type": clean_text(row.get("tipoDocumento")),
            "document_status": clean_text(row.get("estadoBeneficio") or row.get("estadoBeneficioEmitente")),
            "sector": clean_text(row.get("actividadeEmitente") or row.get("actividadeProf")),
            "tax_payable": normalize_minor_amount(row.get("valorTotalIva")),
            "net_total": normalize_minor_amount(row.get("valorTotalBaseTributavel")),
            "gross_total": normalize_minor_amount(row.get("valorTotal")),
            "source_hash": hashlib.sha256(source_basis.encode("utf-8")).hexdigest(),
            "lines": [],
            "raw_row": row,
            "raw_headers": list(row.keys()),
        }

    mapped = {str(key).lower(): clean_text(value) for key, value in row.items()}
    invoice_date = first_match(mapped, list(mapped.values()), [r"\d{4}-\d{2}-\d{2}", r"\d{2}/\d{2}/\d{4}"])
    invoice_no = pick_field(mapped, list(mapped.values()), ["documento", "fatura", "factura", "numero", "numdoc", "nrdoc"])
    issuer_name = pick_field(mapped, list(mapped.values()), ["emitente", "fornecedor", "transmitente", "nome"])
    issuer_vat = extract_nif(pick_field(mapped, list(mapped.values()), ["nif emitente", "nif fornecedor", "nif", "contribuinte"]))
    invoice_type = pick_field(mapped, list(mapped.values()), ["tipo", "tipodoc", "documenttype"])
    net_total = extract_amount(pick_field(mapped, list(mapped.values()), ["liquido", "net", "base", "valorbase"]))
    tax_payable = extract_amount(pick_field(mapped, list(mapped.values()), ["iva", "imposto", "tax"]))
    gross_total = extract_amount(pick_field(mapped, list(mapped.values()), ["total", "bruto", "gross", "montante"]))

    source_basis = "|".join([company_vat or "", issuer_vat or "", invoice_no or "", invoice_date or "", gross_total])
    if not source_basis.strip("|"):
        return None
    if not invoice_no:
        invoice_no = "ROW-" + hashlib.sha1(json.dumps(row, sort_keys=True, ensure_ascii=False).encode("utf-8")).hexdigest()[:12]
    if not invoice_date:
        invoice_date = datetime.now().strftime("%Y-%m-%d")

    document = {
        "issuer_vat": issuer_vat,
        "issuer_name": issuer_name,
        "customer_vat": company_vat or "",
        "invoice_no": invoice_no,
        "atcud": pick_field(mapped, list(mapped.values()), ["atcud"]),
        "invoice_date": normalize_date(invoice_date),
        "invoice_type": invoice_type,
        "document_status": pick_field(mapped, list(mapped.values()), ["estado", "status"]),
        "sector": pick_field(mapped, list(mapped.values()), ["setor", "sector"]),
        "tax_payable": tax_payable,
        "net_total": net_total,
        "gross_total": gross_total,
        "source_hash": hashlib.sha256(source_basis.encode("utf-8")).hexdigest(),
        "lines": [],
        "raw_row": row,
        "raw_headers": list(mapped.keys()),
    }
    return document if is_plausible_efatura_document(document) else None


def extract_documents(page, company_vat, debug, row_selectors):
    headers = extract_headers(page)
    rows = []
    for selector in row_selectors:
        try:
            locator = page.locator(selector)
            count = locator.count()
            if count <= 0:
                continue
            for idx in range(count):
                cells = [clean_text(text) for text in locator.nth(idx).locator("th,td").all_inner_texts()]
                cells = [cell for cell in cells if cell]
                if len(cells) >= 4:
                    rows.append(cells)
            if rows:
                step(debug, f"Linhas encontradas com seletor {selector}: {len(rows)}")
                break
        except Exception:
            continue

    if not rows:
        return []

    documents = []
    for row in rows:
        document = normalize_row(headers, row, company_vat)
        if document:
            documents.append(document)

    unique = {}
    for document in documents:
        unique[document["source_hash"]] = document
    return list(unique.values())


def extract_headers(page):
    try:
        headers = page.locator("table thead tr").first.locator("th,td").all_inner_texts()
        return [clean_text(item).lower() for item in headers if clean_text(item)]
    except Exception:
        return []


def normalize_row(headers, row, company_vat):
    mapped = {}
    if headers and len(headers) == len(row):
        mapped = {headers[i]: row[i] for i in range(len(row))}

    values = row[:]
    invoice_date = first_match(mapped, values, [r"\d{4}-\d{2}-\d{2}", r"\d{2}/\d{2}/\d{4}"])
    invoice_no = pick_field(mapped, values, ["documento", "fatura", "invoice", "numero"], fallback_index=4)
    issuer_name = pick_field(mapped, values, ["emitente", "fornecedor", "transmitente"], fallback_index=1)
    issuer_vat = extract_nif(pick_field(mapped, values, ["nif emitente", "nif fornecedor", "nif"], fallback_index=2))
    invoice_type = pick_field(mapped, values, ["tipo"], fallback_index=5)
    net_total = extract_amount(pick_field(mapped, values, ["liquido", "net", "base"], fallback_index=6))
    tax_payable = extract_amount(pick_field(mapped, values, ["iva", "imposto", "tax"], fallback_index=7))
    gross_total = extract_amount(pick_field(mapped, values, ["total", "bruto", "gross"], fallback_index=8))

    if not invoice_no:
        invoice_no = "ROW-" + hashlib.sha1("|".join(values).encode("utf-8")).hexdigest()[:12]
    if not invoice_date:
        invoice_date = datetime.now().strftime("%Y-%m-%d")

    source_basis = "|".join([company_vat or "", issuer_vat or "", invoice_no, invoice_date, gross_total])
    document = {
        "issuer_vat": issuer_vat,
        "issuer_name": issuer_name,
        "customer_vat": company_vat or "",
        "invoice_no": invoice_no,
        "atcud": "",
        "invoice_date": normalize_date(invoice_date),
        "invoice_type": invoice_type,
        "document_status": "",
        "sector": "",
        "tax_payable": tax_payable,
        "net_total": net_total,
        "gross_total": gross_total,
        "source_hash": hashlib.sha256(source_basis.encode("utf-8")).hexdigest(),
        "lines": [],
        "raw_row": values,
        "raw_headers": headers,
    }
    return document if is_plausible_efatura_document(document) else None


def is_plausible_efatura_document(document):
    if not isinstance(document, dict):
        return False

    invoice_no = clean_text(document.get("invoice_no"))
    invoice_date = clean_text(document.get("invoice_date"))
    issuer_name = clean_text(document.get("issuer_name"))
    issuer_vat = extract_nif(clean_text(document.get("issuer_vat")))
    invoice_type = clean_text(document.get("invoice_type")).upper()
    net_total = extract_amount(document.get("net_total"))
    tax_payable = extract_amount(document.get("tax_payable"))
    gross_total = extract_amount(document.get("gross_total"))

    allowed_types = {"FT", "FR", "FS", "NC", "ND", "RC", "FA", "VD", "TV", "DC", "DU"}
    has_valid_date = bool(re.fullmatch(r"\d{4}-\d{2}-\d{2}", invoice_date))
    has_number_shape = bool(re.search(r"\d", invoice_no)) and "ROW-" not in invoice_no
    has_known_type = invoice_type in allowed_types
    has_nonzero_amount = any(value != "0.00" for value in [net_total, tax_payable, gross_total])
    has_valid_issuer = issuer_vat != "" or issuer_name not in {"", "Geral", "A AT", "O Seu Espaço"}

    if not has_valid_date:
        return False
    if not has_valid_issuer:
        return False
    if not (has_number_shape or has_known_type):
        return False
    if not (has_nonzero_amount or issuer_vat or has_number_shape):
        return False
    return True


def pick_field(mapped, values, name_parts, fallback_index=None):
    for key, value in mapped.items():
        normalized = key.lower()
        if any(part in normalized for part in name_parts):
            return clean_text(value)
    if fallback_index is not None and len(values) > fallback_index:
        return clean_text(values[fallback_index])
    return ""


def first_match(mapped, values, patterns):
    compiled = [re.compile(pattern) for pattern in patterns]
    candidates = list(mapped.values()) + values
    for candidate in candidates:
        text = clean_text(candidate)
        if any(regex.search(text) for regex in compiled):
            return text
    return ""


def extract_nif(value):
    match = re.search(r"\b(\d{9})\b", value or "")
    return match.group(1) if match else ""


def extract_amount(value):
    raw = clean_text(value)
    if not raw:
        return "0.00"
    raw = raw.replace(" ", "").replace("\u00a0", "")
    cleaned = re.sub(r"[^0-9,.\-]", "", raw)
    if "," in cleaned and "." in cleaned:
        cleaned = cleaned.replace(".", "").replace(",", ".")
    elif "," in cleaned:
        cleaned = cleaned.replace(",", ".")
    try:
        return f"{float(cleaned):.2f}"
    except Exception:
        return "0.00"


def normalize_minor_amount(value):
    if value is None or value == "":
        return "0.00"
    if isinstance(value, int):
        return f"{value / 100:.2f}"
    if isinstance(value, float):
        return f"{value:.2f}"
    raw = clean_text(value)
    if re.fullmatch(r"-?\d+", raw or ""):
        return f"{int(raw) / 100:.2f}"
    return extract_amount(raw)


def clean_text(value):
    text = html.unescape(re.sub(r"\s+", " ", str(value or "")).strip())
    return text


def normalize_date(value):
    value = clean_text(value)
    for fmt in ("%Y-%m-%d", "%d/%m/%Y", "%d-%m-%Y"):
        try:
            return datetime.strptime(value, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue
    return value


def save_debug_snapshot(page, artifact_path, debug):
    base = Path(artifact_path)
    html_path = base.with_suffix(".html")
    png_path = base.with_suffix(".png")
    try:
        html_path.write_text(page.content(), encoding="utf-8")
        debug["html_snapshot"] = str(html_path)
    except Exception:
        pass
    try:
        page.screenshot(path=str(png_path), full_page=True)
        debug["screenshot"] = str(png_path)
    except Exception:
        pass


if __name__ == "__main__":
    sys.exit(main())
