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
4. Aplique as migrações para alinhar a base com a versão atual da aplicação:
   ```bash
   php scripts/migrate.php
   ```
5. Configure as credenciais da base de dados em [`data/db.php`](data/db.php).
6. Inicie o servidor de desenvolvimento:
   ```bash
   php -S localhost:8000 router.php
   ```
   Ou configure o seu servidor web para apontar para a pasta do projeto e ativar a reescrita de URL com o ficheiro `.htaccess` fornecido.
7. Aceda a `http://localhost:8000/login` e autentique-se com as credenciais de administrador.

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

## Área reservada de clientes (Extranet)
Cada entidade adquirente pode ter contas de acesso à sua área reservada (`client_users`), geridas no separador **Extranet > Gestão de utilizadores** da ficha da entidade. O portal do cliente é servido pelas rotas `t/{tenant}/cliente/...`.

- **Impersonar**: utilizadores de back-office com perfil `superadmin`/`administrador` têm, em cada conta da lista, um botão **Impersonar** que abre a área reservada desse cliente num novo separador, sem necessidade de introduzir credenciais.
- A impersonação não termina a sessão de back-office; durante a mesma, o portal mostra um banner com a opção **Terminar impersonação**, que regressa à ficha da entidade.
- Cada início/fim de impersonação fica registado no audit log (`impersonate` / `stop-impersonate`) com o identificador de quem a executou.

## Assistente AI: memória persistente
O assistente AI guarda memória e contexto entre sessões na tabela `ai_assistant_logs`.

- Por defeito, as instruções/mensagens do utilizador são memorizadas para melhorar respostas futuras.
- Em pedidos técnicos, o assistente pode ler funções PHP do projeto para explicar procedimentos e cálculos com base no código real.
- O assistente também memoriza tarefas contabilísticas relevantes para reutilização em respostas futuras.
- Para anexos no chat (ex.: PDF), o assistente pode extrair texto com leitor documental Python (`scripts/ai_document_reader.py`) quando a ferramenta `read_uploaded_document` é usada.
- Na sugestão de contas, o assistente cruza emitente + adquirente + tipo de documento com histórico MySQL e reforça com endpoints ERP (`LigacaoCteTipoDoc`, `movimentos`, `planocontas`, `taxonomias`) para melhorar a precisão.
- No endpoint ERP `contabilidade/LigacaoCteTipoDoc`, o mapeamento usado pela sugestão IA é:
  - `strConta` -> conta geral
  - `strConta_Iva` -> conta IVA
  - linha `strTipo = C` / conta da entidade -> conta de valor total (`total_account`)
- Neste endpoint, a associação da linha ERP à taxa de IVA do documento deve ser feita prioritariamente por `PC_Descricao`:
  - `TAXA REDUZIDA` = `6%`
  - `TAXA INTERMEDIA` / `TAXA INTERMÉDIA` = `13%`
  - `TAXA NORMAL` = `23%`
- Os campos numéricos `fltVatRate`, `fltTaxaValor` e semelhantes podem vir a `0`/`.000000`, por isso não devem ser a fonte principal para descobrir a taxa.
- A chamada para `LigacaoCteTipoDoc` deve ser dinâmica por documento (dados do QR):
  - `datadoc` (data do documento do QR, formato `YYYY-MM-DD`)
  - `strTpDoc` (tipo documental do QR, ex.: `FT`)
  - A seleção final da linha correta por taxa e o cruzamento com o QR/documento são feitos na aplicação, usando `PC_Descricao` quando o webservice não devolve a taxa explícita de forma fiável.
- `planocontas` deve ser usado como fallback (última opção), apenas quando histórico/regras/ligação/movimentos não forem suficientes.
- Se o utilizador indicar correção/remoção (ex.: `esquece`, `errado`, `incorreto`, `ignora`), essa informação não deve ser consolidada como memória válida.
- Comandos disponíveis no chat:
  - `memoriza: ...`
  - `listar memorias`
  - `esquece: ...`
  - `esquece memorias`

## Integração ERP
O módulo de contabilidade consulta o webservice **ERP-SINC** para sincronizar dados de clientes. Certifique-se de que a URL e token do serviço estão configurados em **Definições > ERP** e que a empresa base está definida em **Módulos > Contabilidade > Empresa base**. O sincronismo passa a depender exclusivamente do ERP-SINC, não recorrendo a dados alternativos do [NIF.pt](https://www.nif.pt/).

- Nas chamadas do assistente ao ERP, é enviado `EMP` e, quando aplicável, também `db` para compatibilidade.
- No módulo de contabilidade (consultas e importação), as chamadas ao ERP também enviam `EMP` e `db`/`database` quando aplicável.
- O valor de `EMP` passa a ser sempre a `Empresa base` do módulo de Contabilidade.
- Em operações POST específicas, o identificador de empresa pode ser enviado como `database`.
- Fontes de referência da API:
  - OpenAPI atualizada: `/Users/nelsonsantos/Sites2026/api.erpsinc.pt/erpsync-api.yaml`
  - Repositório partilhado local: `/Users/nelsonsantos/Sites2026/api.erpsinc.pt` (apenas leitura/consulta)

### Fluxo Classificacao / Importacao CTB

#### Permissões (CTB Documentos)
- `ctb_classificar_docs`
  - Permite classificar documentos na vista de Classificação (`Classificar` por linha).
  - Sem esta permissão, o botão por linha aparece desativado (`Sem permissao`).
- `ctb_importar_docs`
  - Permite aceder ao item de menu **Importação** (`type=import`) e abrir a vista dedicada de importação.
  - Sem esta permissão, a URL `?type=import` responde com `403`.
- Perfis `superadmin` e `administrador`
  - Têm acesso total às permissões departamentais (`userHasDepartmentPermission` devolve `true` para estes perfis).

#### Workflow funcional (import_type=1)
1. **Classificação**: `contabilidade/classificacao-importacao?import_type=1`
   - Mostra todos os documentos pendentes.
   - Botão por linha:
     - `Classificar` quando a linha ainda não está pronta.
     - `Classificado` (verde) quando a linha já cumpre os critérios.
   - Botão global `Classificado`:
     - Opera sobre as linhas verdes.
     - Permite processar/importar diretamente a partir desta vista.
   - No modal de classificação, existe o botão `Explicação da sugestão` para justificar por taxa as contas sugeridas (histórico/regras/ERP ligação+movimentos/plano).
   - A coluna visível do emitente mostra apenas o NIF do emitente.
2. **Importação**: `contabilidade/classificacao-importacao?import_type=1&type=import`
   - Mostra apenas linhas verdes (prontas).
   - Botão global `Importar Ctb` para enviar as linhas selecionadas ao ERP.
   - O acesso no menu lateral fica visível apenas com `ctb_importar_docs`.

#### Regras de leitura por ficheiro PDF
- O mesmo PDF pode conter multiplas faturas; o sistema preserva todas as `FT`/`FR` encontradas no ficheiro.
- Se o mesmo ficheiro contiver `FT`/`FR` e recibos `RC`/`RG`, os recibos sao ignorados e ocultados nas vistas de upload e classificacao; apenas as faturas seguem para classificacao/importacao.
- Quando uma fatura vem no mesmo PDF com um `RC`/`RG`, esse contexto e guardado para a sugestao de contas, sobretudo para a conta de `Valor Total`.
- Na ligacao ERP `LigacaoCteTipoDoc`, documentos `FR`/`FTR` sao tratados como `FT` para obter a parametrizacao contabilistica correta.

#### Regras adicionais de classificacao
- Numa linha de taxa `0%`, `Conta IVA` deve ficar vazia; isso nao impede o estado `Classificado` (verde).
- O botao global `Classificado` importa a configuracao contabilistica efetiva do documento (`linha + classificacao generica`), mesmo que a modal nunca tenha sido aberta.
- Quando um fornecedor nao existe em `LigacaoCteTipoDoc`, a sugestao deixa de inventar `Conta Geral`; nesse caso, apenas pode sugerir `Valor Total` para contas de bancos (`12...`) se a fatura tiver recibo `RC`/`RG` no mesmo PDF.
- A explicacao da sugestao mostra explicitamente quando o fornecedor nao foi encontrado no ERP.

#### Memoria e modelos de sugestao
- A memoria da conta de `Valor Total` distingue faturas normais de faturas com recibo associado no mesmo ficheiro.
- Para esse contexto, a classificacao generica guarda tambem `receipt_total_account`, evitando perder a memoria quando o registo individual de `accounting_imports.account` e limpo.
- Os modelos guardados pelo utilizador preservam `rates`, `centros de custo` e `Valor Total`.

### Lancamentos ERP
- Em `contabilidade/lancamentos`, fechar a modal volta a sincronizar a grelha com o ERP, evitando desaparecimentos visuais da linha.
- Ao eliminar um lancamento, os anexos digitais sao apagados primeiro; se a remocao do anexo falhar, o lancamento nao e eliminado.
- Quando o ERP recusa uma importacao por documento duplicado, a resposta passa a indicar em que exercicio/diario/mes/n. diario o documento ja existe.
- Na edicao de lancamentos, notas de credito (`NC`) preservam a linha de `Valor Total` e respeitam a natureza debito/credito correta.

### E-fatura
- Em `contabilidade/efatura/documentos`, o calendario do filtro por datas mostra os dois meses lado a lado em desktop.

#### Resumo prático
- Utilizador com `ctb_classificar_docs` e sem `ctb_importar_docs`:
  - Classifica na vista principal.
  - Não vê/abre a vista dedicada de Importação.
- Utilizador com `ctb_importar_docs`:
  - Tem acesso à vista dedicada de Importação e ao botão `Importar Ctb`.

### Diagnóstico de erros de importação CTB
Se a importação CTB falhar com a mensagem `Erro: O webservice de contabilidade devolveu uma resposta vazia`, confirme primeiro a conectividade com o serviço externo. Pode utilizar `curl` para validar o endpoint e inspecionar a resposta. Exemplos úteis:

- Ignorar certificados TLS autoassinados durante os testes (utilize a forma longa `--insecure` para enfatizar que a validação SSL é ignorada):

  ```bash
  curl --insecure "https://erp.exemplo.tld/endpoint"
  ```

- Enviar uma requisição `POST` com dados JSON e cabeçalhos explícitos:

  ```bash
  curl --insecure -X POST "https://erp.exemplo.tld/endpoint" \
       -H "Content-Type: application/json" \
       -H "Authorization: Bearer SEU_TOKEN" \
       -d '{"operacao":"importacao","referencia":"123"}'
  ```

- Registar o pedido e a resposta num ficheiro para análise posterior:

  ```bash
  curl --insecure -X POST "https://erp.exemplo.tld/endpoint" \
       -d '{"operacao":"importacao"}' \
       -H "Content-Type: application/json" \
       -o resposta.json -D cabecalhos.txt -v
  ```

Com estes comandos é possível verificar rapidamente se o webservice está acessível, se devolve conteúdo e se existem cabeçalhos ou códigos de estado HTTP inesperados. Guarde os ficheiros de saída (`resposta.json`, `cabecalhos.txt`) para partilhar com a equipa de suporte ou com o fornecedor do ERP.

## Textract OCR
O módulo de contabilidade pode utilizar o [AWS Textract](https://aws.amazon.com/textract/) para extrair dados de faturas. A integração é feita através do script Python `contabilidade/textract.py`, que requer a biblioteca `boto3`.

1. Aceda a **Definições > E-mail** e selecione **AWS Textract** no campo **OCR**.
2. Preencha **AWS Access Key ID**, **AWS Secret Access Key** e **AWS Region** ou defina as variáveis de ambiente `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` e `AWS_REGION`.
3. Defina também `AWS_TEXTRACT_BUCKET` com o bucket S3 a utilizar.
4. Quando ativo, o sistema usa o script Python para analisar faturas e, em caso de erro, reverte para o Tesseract registando a falha nos logs.

## Licença
Distribuído sob a licença MIT. Consulte o ficheiro [`LICENSE`](LICENSE) se existir ou adapte conforme necessário.
