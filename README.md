# CMS Pombaldir

Sistema de gestão de conteúdos simples escrito em PHP. O objetivo é fornecer uma base mínima para criar e gerir tipos de conteúdo, campos personalizados e taxonomias através de uma interface baseada no tema [Gentelella](https://colorlibhq.github.io/gentelella/).

## Funcionalidades
- Gestão de utilizadores com diferentes níveis de permissão (superadmin, administrador e utilizador).
- Criação de tipos de conteúdo com campos personalizados.
- Definição de taxonomias e termos.
- Interface de administração responsiva usando Bootstrap e Gentelella.
- Sistema de autenticação com proteção CSRF.
- Endpoint de API para consulta de taxonomias e termos através de token.

## Requisitos
- PHP \>= 7.4
- MySQL ou MariaDB
- Servidor web com suporte a reescrita de URL (Apache, Nginx ou `php -S`).

## Instalação
1. Clone o repositório:
   ```bash
   git clone https://github.com/Pombaldir-com/cms-pombaldir.git
   cd cms-pombaldir
   ```
2. Instale as dependências com o Composer:
   ```bash
   composer install
   ```
3. Crie a base de dados e importe o esquema:
   ```bash
   mysql -u root -p < schema.sql
   ```
   O script cria um utilizador de exemplo `admin` com a palavra‑passe `admin123`.
4. Configure as credenciais da base de dados em [`data/db.php`](data/db.php).
5. Inicie o servidor de desenvolvimento:
   ```bash
   php -S localhost:8000 router.php
   ```
   Ou configure o seu servidor web para apontar para a pasta do projeto e ativar a reescrita de URL com o ficheiro `.htaccess` fornecido.
6. Aceda a `http://localhost:8000/login` e autentique-se com as credenciais de administrador.

## Estrutura
- `assets/` – ficheiros CSS e JS adicionais.
- `data/` – configuração de base de dados e scripts auxiliares.
- `vendors/` – dependências front-end (Bootstrap, jQuery, etc.).
- `*.php` – páginas e endpoints principais do CMS.

## API
O projeto disponibiliza um endpoint simples em [`api.php`](api.php) para aceder a taxonomias e respetivos termos em formato JSON.

1. No painel de administração, aceda a **Definições** e ative a opção **Ativar API**.
2. Defina um token secreto que será usado nas chamadas.
3. Faça pedidos para `api.php` com os parâmetros `token` e `taxonomy_slug`:

   ```bash
   curl "http://localhost:8000/api.php?token=SEU_TOKEN&taxonomy_slug=Categorias"
   ```

O endpoint responde com informação da taxonomia e a lista de termos associados.

## Textract OCR
O módulo de contabilidade pode utilizar o [AWS Textract](https://aws.amazon.com/textract/) para extrair dados de faturas.

1. Aceda a **Definições > E-mail** e selecione **AWS Textract** no campo **OCR**.
2. Preencha **AWS Access Key ID**, **AWS Secret Access Key** e **AWS Region** ou defina as variáveis de ambiente `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` e `AWS_REGION`.
3. Quando ativo, o sistema usa Textract para analisar faturas e, em caso de erro, reverte para o Tesseract registando a falha nos logs.

## Licença
Distribuído sob a licença MIT. Consulte o ficheiro [`LICENSE`](LICENSE) se existir ou adapte conforme necessário.
