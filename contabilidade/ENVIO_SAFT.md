# Envio de SAF-T para a AT (FACTEMICLI)

Referência da integração com o cliente de linha de comandos da AT
(`FACTEMICLI-[VERSAO]-cmdClient.jar`) usado para submeter o ficheiro SAF-T
de faturação. Durante o processo de submissão será extraída, e posteriormente
enviada, a informação relevante das faturas emitidas de acordo com o n.º 4 do
Art. 3.º do Decreto-Lei 198/2012.

## Integração na aplicação

- Página: `contabilidade/tarefas/envio-saft` (tarefa "Envio de SAF-T").
- A permissão por empresa é gerida na ficha da empresa (Entidades > Empresas >
  separador Admin > Tarefas administrativas > "Envio de SAF-T").
- Credenciais do portal AT por empresa: reutiliza a tabela
  `efatura_company_credentials` (`portal_username` no formato `NIF` ou
  `NIF/subutilizador`, password cifrada com `EFATURA_SECRET_KEY`).
- Configuração em Definições > Serviços:
  - `saft_jar_path` — caminho absoluto para o jar FACTEMICLI no servidor.
  - `saft_java_bin` — binário Java a usar (por defeito `java`).
- Cada envio fica registado em `accounting_saft_submissions`, incluindo a
  resposta da AT (código, totais, warning, id do ficheiro, erros e XML bruto).

## Requisitos mínimos de utilização

- OpenJDK 8 ou Java 8 SE ou superior no classpath da máquina
  (`java -version` para confirmar).
- O relógio da máquina tem de estar sincronizado com o Observatório
  Astronómico de Lisboa. Caso contrário a autenticação falha com
  `ERROR CODE: 11: Validade da credencial expirada`.
- O processo é interrompido caso existam inconsistências nos totais dos
  documentos do ficheiro. Durante um período de adaptação o utilizador pode
  optar por continuar (`s/n` no modo interativo), mas brevemente deixarão de
  ser aceites ficheiros nestas condições.

## Versão SAF-T

A versão indicada no elemento `AuditFileVersion` do ficheiro a enviar poderá
ser um dos seguintes valores:

| Versão    | Base legal              |
|-----------|-------------------------|
| `1.02_01` | Portaria n.º 160/2013   |
| `1.03_01` | Portaria n.º 274/2013   |
| `1.04_01` | Portaria n.º 302/2016   |

**O formato `1.01_01` (Portaria n.º 1192/2009) deixou de ser aceite a partir
de 1 de abril de 2014.**

## Exemplo de utilização

Configuração mínima para envio:

```
java -jar FACTEMICLI-[VERSAO]-cmdClient.jar -n 123456789 -p xxxxxxxxx -a 2013 -m 01 -op enviar \
    -i "C:\caminho para ficheiro\Nome_ficheiro.xml"
```

Com ficheiro de saída para o resultado do processamento e ajuste de memória
(o NIF pode incluir subutilizador, ex.: `123456789/14`):

```
java -Xms:256m -Xmx:1024m -jar FACTEMICLI-[VERSAO]-cmdClient.jar -n 123456789/14 -p xxxxxxxxx -a 2013 -m 01 -op enviar \
    -i "C:\caminho para ficheiro\Nome_ficheiro.xml" -o "C:\caminho para ficheiro\Nome_ficheiro_saida.xml"
```

Quando o ficheiro submetido contém inconsistências nos totais, o cliente
pergunta interactivamente:

```
Informa-se que os elementos de controlo do ficheiro apresentam diferenças, pelo que será oportuno
contactar a empresa produtora de software.
Brevemente não será possível comunicar ficheiros que evidenciem estas anomalias.

                        |  NumberOfEntries  NumberOfEntries Calculado  TotalDebit  TotalDebit Calculado  TotalCredit  TotalCredit Calculado
------------------------------------------------------------------------------------------------------------------------------------------
  Documentos de Faturação |               17                        17      950.00                950.00     19846.12            18846.12000
Documentos de Conferência |                4                         4     0.00000                     0     10500.00            10500.00000
                  Recibos |                4                         4        0.00                     0     1428.616                1428.616

Deseja continuar a comunicação dos documentos mesmo com esta anomalia? (s/n)
```

A aplicação responde a este prompt via stdin (`s` para forçar, `n` para
abortar), consoante a opção escolhida pelo utilizador no formulário de envio.

## Estrutura de resposta (XML)

Especificação XSD da resposta do servidor:

```xml
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
    <xs:element name="response">
        <xs:complexType>
            <xs:choice>
                <xs:element name="errors" type="errorType" minOccurs="1"/>
                <xs:sequence>
                    <xs:element name="totalFaturas" type="xs:string" maxOccurs="1" minOccurs="1"/>
                    <xs:element name="totalCreditos" type="xs:string" maxOccurs="1" minOccurs="1"/>
                    <xs:element name="totalDebitos" type="xs:string" maxOccurs="1" minOccurs="1"/>
                    <xs:element name="warning" type="xs:string" maxOccurs="1" minOccurs="0"/>
                    <xs:element name="idFicheiro" type="xs:string" maxOccurs="1" minOccurs="0"/>
                    <xs:element name="nomeFicheiro" type="xs:string" maxOccurs="1" minOccurs="1"/>
                    <xs:element name="createdDate" type="xs:string" maxOccurs="1" minOccurs="1"/>
                </xs:sequence>
            </xs:choice>
            <xs:attribute name="code" type="xs:string" use="required"/>
        </xs:complexType>
    </xs:element>
    <xs:complexType name="errorType">
        <xs:sequence>
            <xs:element name="error" type="xs:string" maxOccurs="unbounded" minOccurs="1"/>
        </xs:sequence>
    </xs:complexType>
</xs:schema>
```

Exemplo de resposta de sucesso (se o ficheiro for aceite com condicionantes,
é incluída uma mensagem `warning`):

```xml
<?xml version="1.0" encoding="ISO-8859-1"?>
<response code="200">
    <totalFaturas>10</totalFaturas>
    <totalCreditos>1234.56</totalCreditos>
    <totalDebitos>12.34</totalDebitos>
    <warning>Devido a todas as faturas serem anteriores a 1/Jan/2013 o ficheiro não será considerado para processamento.</warning>
    <idFicheiro>123</idFicheiro>
    <nomeFicheiro>saft-pt.xml</nomeFicheiro>
    <createdDate>2013-02-01 15:17:54</createdDate>
</response>
```

Exemplo de resposta com erro no envio ou validação:

```xml
<?xml version="1.0" encoding="ISO-8859-1"?>
<response code="-3">
    <errors>
        <error>NIF do emitente ('123456789') é diferente do NIF declarado no ficheiro ('987654321').</error>
    </errors>
</response>
```

## Códigos de resposta

| Código | Mensagem de erro | Descrição |
|--------|------------------|-----------|
| `-1`   | Ocorreu um erro durante o envio do ficheiro. | Erro genérico na comunicação entre cliente e servidor. |
| `-2`   | O ficheiro recebido não tem o mesmo tamanho que o ficheiro enviado. | Tamanho do ficheiro declarado no header pelo programa cliente não corresponde ao tamanho real enviado. |
| `-3`   | Mensagem específica da validação que não está a ser respeitada. | A mensagem de resposta para este erro é variável, dependente da validação que não é respeitada. |
| `-4`   | Ocorreu um erro durante o envio do ficheiro. | Erro ao inserir o ficheiro na base de dados. |
| `-5`   | O ficheiro selecionado já foi enviado para a AT. | Um ficheiro idêntico foi previamente enviado para a AT. |
| `-6`   | Erro no processo de conversão. | Problema durante o processo de conversão; é apresentada mensagem complementar com a origem do erro. |
| `-7`   | O cliente de linha de comandos não se encontra atualizado. | Obter a nova versão no portal e-fatura. |
| `-8`   | O ficheiro resumido não pode ser o mesmo que o ficheiro selecionado para envio. | O ficheiro do parâmetro `-i` é o mesmo que o do parâmetro `-r` (ficheiro resumido). |
| `-9`   | Para entregar o ficheiro nesta versão necessita de atualizar o cliente. | O cliente não é a versão mais atual para o formato de ficheiro em causa. |
| `-401` | Login failed for user 123456789. ERROR CODE: `<ERRO AUTENTICAÇÃO>` | Erro na autenticação no servidor. |
| `-666` | Ocorreu um erro. | Erro não categorizado durante o envio; é apresentada mensagem descritiva. |
| `200`  | — | Sucesso no envio do ficheiro. |
