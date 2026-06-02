"""
Envio de e-mail via Microsoft Graph API (Azure AD client credentials).
"""
from __future__ import annotations

import httpx
from config import get_settings


async def _get_access_token() -> str:
    settings = get_settings()
    url = f"https://login.microsoftonline.com/{settings.AZURE_TENANT_ID}/oauth2/v2.0/token"
    async with httpx.AsyncClient(timeout=15) as client:
        resp = await client.post(url, data={
            "grant_type": "client_credentials",
            "client_id": settings.AZURE_CLIENT_ID,
            "client_secret": settings.AZURE_CLIENT_SECRET,
            "scope": "https://graph.microsoft.com/.default",
        })
        resp.raise_for_status()
        return resp.json()["access_token"]


async def _enviar_html(nome: str, email: str, assunto: str, html: str) -> bool:
    settings = get_settings()
    if not settings.AZURE_TENANT_ID or not settings.GRAPH_SENDER:
        return False
    try:
        token = await _get_access_token()
        async with httpx.AsyncClient(timeout=15) as client:
            resp = await client.post(
                f"https://graph.microsoft.com/v1.0/users/{settings.GRAPH_SENDER}/sendMail",
                headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
                json={
                    "message": {
                        "subject": assunto,
                        "body": {"contentType": "HTML", "content": html},
                        "toRecipients": [{"emailAddress": {"address": email, "name": nome}}],
                    },
                    "saveToSentItems": False,
                },
            )
            return resp.status_code == 202
    except Exception:
        return False


async def enviar_reset_senha(nome: str, email: str, senha: str) -> bool:
    """Envia e-mail informando que a senha foi redefinida pelo administrador."""
    settings = get_settings()
    html = f"""
    <div style="font-family:Inter,Arial,sans-serif;max-width:520px;margin:0 auto;background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
      <div style="background:linear-gradient(135deg,#E8001C,#8B5CF6);padding:28px 32px">
        <h1 style="color:#fff;margin:0;font-size:20px;font-weight:700">BH Pulse</h1>
        <p style="color:rgba(255,255,255,.8);margin:6px 0 0;font-size:13px">Redefinição de senha</p>
      </div>
      <div style="padding:32px">
        <p style="color:#374151;font-size:15px;margin:0 0 16px">Olá, <strong>{nome}</strong>!</p>
        <p style="color:#6b7280;font-size:14px;margin:0 0 24px">Sua senha foi redefinida pelo administrador. Use a senha temporária abaixo para acessar:</p>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:24px">
          <p style="margin:0 0 10px;font-size:13px;color:#6b7280"><strong style="color:#374151">Login (e-mail):</strong><br>{email}</p>
          <p style="margin:0;font-size:13px;color:#6b7280"><strong style="color:#374151">Senha temporária:</strong><br>
            <span style="font-family:monospace;font-size:16px;color:#E8001C;letter-spacing:1px">{senha}</span>
          </p>
        </div>
        <p style="color:#6b7280;font-size:13px;margin:0 0 20px">Por segurança, você será solicitado a criar uma nova senha no próximo acesso.</p>
        <a href="{settings.APP_URL}" style="display:inline-block;background:#E8001C;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600">Acessar o sistema →</a>
      </div>
      <div style="padding:16px 32px;border-top:1px solid #f3f4f6;background:#fafafa">
        <p style="margin:0;font-size:12px;color:#9ca3af">Hostweb · Este e-mail é automático, não responda. Se você não solicitou, contate a TI.</p>
      </div>
    </div>
    """
    return await _enviar_html(nome, email, "Senha redefinida — BH Pulse", html)


async def enviar_acesso(nome: str, email: str, senha: str) -> bool:
    """Envia e-mail de boas-vindas com credenciais de acesso."""
    settings = get_settings()
    if not settings.AZURE_TENANT_ID or not settings.GRAPH_SENDER:
        return False

    html = f"""
    <div style="font-family:Inter,Arial,sans-serif;max-width:520px;margin:0 auto;background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
      <div style="background:linear-gradient(135deg,#E8001C,#8B5CF6);padding:28px 32px">
        <h1 style="color:#fff;margin:0;font-size:20px;font-weight:700">BH Pulse</h1>
        <p style="color:rgba(255,255,255,.8);margin:6px 0 0;font-size:13px">Sistema de Banco de Horas</p>
      </div>
      <div style="padding:32px">
        <p style="color:#374151;font-size:15px;margin:0 0 16px">Olá, <strong>{nome}</strong>!</p>
        <p style="color:#6b7280;font-size:14px;margin:0 0 24px">Sua conta no BH Pulse foi criada. Use as credenciais abaixo para acessar o sistema:</p>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:24px">
          <p style="margin:0 0 10px;font-size:13px;color:#6b7280"><strong style="color:#374151">Login (e-mail):</strong><br>{email}</p>
          <p style="margin:0;font-size:13px;color:#6b7280"><strong style="color:#374151">Senha inicial:</strong><br>
            <span style="font-family:monospace;font-size:16px;color:#E8001C;letter-spacing:1px">{senha}</span>
          </p>
        </div>
        <p style="color:#6b7280;font-size:13px;margin:0 0 20px">Você será solicitado a criar uma nova senha no primeiro acesso.</p>
        <a href="{settings.APP_URL}" style="display:inline-block;background:#E8001C;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600">Acessar o sistema →</a>
      </div>
      <div style="padding:16px 32px;border-top:1px solid #f3f4f6;background:#fafafa">
        <p style="margin:0;font-size:12px;color:#9ca3af">Hostweb · Este e-mail é automático, não responda.</p>
      </div>
    </div>
    """

    try:
        token = await _get_access_token()
        async with httpx.AsyncClient(timeout=15) as client:
            resp = await client.post(
                f"https://graph.microsoft.com/v1.0/users/{settings.GRAPH_SENDER}/sendMail",
                headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
                json={
                    "message": {
                        "subject": "Seus acessos ao BH Pulse — Hostweb",
                        "body": {"contentType": "HTML", "content": html},
                        "toRecipients": [{"emailAddress": {"address": email, "name": nome}}],
                    },
                    "saveToSentItems": False,
                },
            )
            return resp.status_code == 202
    except Exception:
        return False
