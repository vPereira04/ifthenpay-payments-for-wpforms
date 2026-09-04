# ifthenpay | Pagamentos para WPForms

[English](README.md) | **Português** | [Español](README.es.md) | [Français](README.fr.md)

Adiciona métodos de pagamento ifthenpay ao WPForms: cartões, carteiras digitais e métodos de pagamento locais; suporta pagamentos únicos e seguros através de pay-by-link.

---

## Índice

- [Descrição](#descrição)
- [Principais Funcionalidades](#principais-funcionalidades)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração do Formulário](#configuração-do-formulário)
- [Utilizar o ifthenpay em Conjunto com Outro Gateway de Pagamento](#utilizar-o-ifthenpay-em-conjunto-com-outro-gateway-de-pagamento)
- [Perguntas Frequentes](#perguntas-frequentes)
- [Serviços Externos](#serviços-externos)
- [Capturas de Ecrã](#capturas-de-ecrã)
- [Suporte](#suporte)

## Descrição

Este plugin integra o gateway de pagamento ifthenpay com o WPForms para permitir a recolha de pagamentos diretamente nos seus formulários. Os pagamentos são processados através de um sistema seguro de pay-by-link, garantindo que nenhum dado sensível de cartão ou dados bancários é armazenado no seu website. Os clientes podem concluir o pagamento com o método que preferirem através de uma página de pagamento segura. Após submeter um formulário, os utilizadores são redirecionados para a página de pagamento segura e alojada pela ifthenpay para concluir a transação; a ifthenpay envia depois um callback do lado do servidor para atualizar automaticamente o estado do pagamento.

### Em termos simples, obtém:

- Pagamentos únicos diretamente a partir do WPForms
- Suporte para cupões e cálculo automático de totais
- Backoffice do comerciante (vendas básicas) na web e em dispositivos móveis
- Confirmações de pagamento automáticas e seguras (sem armazenamento de números de cartão)

Todas as configurações são feitas no WPForms e no seu Backoffice ifthenpay. O plugin foi criado para que os proprietários de sites possam gerir pagamentos sem necessitar de conhecimentos técnicos avançados.

## Principais Funcionalidades

1. Integração completa com o fluxo de pagamento do WPForms Lite e Pro
2. Transações seguras
3. Confirmação automática de pagamento
4. Suporte para múltiplos métodos de pagamento (cartões, carteiras digitais, transferências)
5. Suporte a cupões e descontos através do WPForms
6. Redirecionamento seguro de página inteira para a página de pagamento alojada pela ifthenpay
7. Estado do pagamento em tempo real nas entradas do WPForms
8. Suporte multi-idioma (EN, ES, FR, PT)
9. Segurança em primeiro lugar (sem armazenamento de dados de cartão)

## Requisitos

- Uma conta de comerciante ifthenpay ativa — [subscreva aqui](https://ifthenpay.com/aderir/) para obter as suas credenciais.
- Uma Chave de Gateway WPForms (solicite-a ao suporte/helpdesk da ifthenpay).
- Os métodos de pagamento que pretende ativar nessa Chave de Gateway (a nossa equipa de helpdesk irá orientá-lo).
- WordPress 6.5+ e PHP 8.2+, com o WPForms instalado e ativado.
- HTTPS (SSL) ativado no seu site.

## Instalação

1. **Instalar:** Carregue o ficheiro zip do plugin em `Plugins → Adicionar Novo → Carregar`, ou instale a partir do WordPress.org, e ative-o.
2. **Credenciais:** Certifique-se de que a sua conta ifthenpay tem uma Chave de Gateway WPForms ativa com os métodos de pagamento pretendidos ativados.
3. **Configuração:** Vá a `WPForms → Definições → Pagamentos` e introduza a sua Backoffice Key.
4. **Configuração do formulário:** `Criar/Editar um formulário → separador Pagamentos → Adicionar o campo Ifthenpay ao formulário → ativar "ifthenpay | Payment Gateway"` e selecione uma Chave de Gateway. De seguida, escolha quais os métodos de pagamento a ativar de entre os disponíveis no seu gateway, e defina o seu método de pagamento predefinido. Por fim, adicione uma descrição de pagamento, que será apresentada na página de pagamento ifthenpay em todas as transações.

## Utilizar o ifthenpay em Conjunto com Outro Gateway de Pagamento

O WPForms apenas permite um método de pagamento ativo por submissão. Se um formulário tiver simultaneamente o campo ifthenpay e o campo de outro gateway de pagamento (PayPal, Stripe, Square ou Authorize.Net) visíveis ao mesmo tempo, o campo ifthenpay oculta-se automaticamente — logótipo, métodos de pagamento e botão "Pagar agora" — para que os clientes vejam sempre apenas a opção de pagamento que realmente funcionará, em vez de um botão que falharia ou cobraria duas vezes.

- O campo ifthenpay volta a aparecer automaticamente se o campo do outro gateway ficar novamente oculto (por exemplo, através da lógica condicional do WPForms), sem ser necessário recarregar a página.
- Isto aplica-se apenas a campos de gateway que estejam efetivamente visíveis no formulário. Um campo de gateway presente mas oculto por lógica condicional não desencadeia este comportamento.
- Campos nativos do WPForms como Total, Cupão e itens de pagamento de escolha única/múltipla/checkbox/lista nunca são tratados como gateways concorrentes — apenas os campos PayPal, Stripe, Square e Authorize.Net o são.

## Perguntas Frequentes

<details>
<summary><strong>Este plugin requer o WPForms?</strong></summary>
Sim. O WPForms tem de estar instalado e ativo para utilizar este plugin.
</details>

<details>
<summary><strong>Suporta pagamentos recorrentes?</strong></summary>
Não. Esta versão suporta apenas pagamentos únicos através de pay-by-link.
</details>

<details>
<summary><strong>Os dados de pagamento são armazenados?</strong></summary>
Não. O plugin não armazena números de cartão nem dados bancários completos. Apenas são guardadas as referências mínimas necessárias para associar o pagamento.
</details>

<details>
<summary><strong>Suporta cupões do WPForms?</strong></summary>
Sim. Os campos de cupão do WPForms são totalmente suportados e os descontos são calculados automaticamente.
</details>

<details>
<summary><strong>Que métodos de pagamento são suportados?</strong></summary>
Qualquer método ifthenpay associado à sua Chave de Gateway (por exemplo, Multibanco, MB WAY, Payshop, Cartão de Crédito, Google Pay, Apple Pay, Pix).
</details>

<details>
<summary><strong>Como funciona o processo de pagamento?</strong></summary>
Após a submissão do formulário, os utilizadores são redirecionados para uma página de pagamento segura alojada pela ifthenpay. Assim que o pagamento é concluído, o estado é atualizado automaticamente através de callback.
</details>

<details>
<summary><strong>O que acontece se um pagamento falhar?</strong></summary>
A entrada é marcada como Falhada. Os utilizadores podem tentar novamente o pagamento, dependendo da sua configuração.
</details>

<details>
<summary><strong>Posso personalizar a experiência de pagamento?</strong></summary>
Sim. Pode configurar o texto do botão, a descrição e o estilo dentro do WPForms.
</details>

<details>
<summary><strong>O que acontece se o meu formulário também tiver outro campo de gateway de pagamento (PayPal, Stripe, Square, Authorize.Net)?</strong></summary>
O campo ifthenpay oculta-se automaticamente enquanto o campo do outro gateway estiver visível, para que os clientes nunca vejam duas opções de pagamento ativas ao mesmo tempo. Consulte <a href="#utilizar-o-ifthenpay-em-conjunto-com-outro-gateway-de-pagamento">Utilizar o ifthenpay em Conjunto com Outro Gateway de Pagamento</a>.
</details>

<details>
<summary><strong>Existe uma sandbox?</strong></summary>
A ifthenpay pode disponibilizar entidades de teste; caso não estejam disponíveis, utilize um teste real de baixo valor.
</details>

<details>
<summary><strong>Quão segura é a integração?</strong></summary>
Os pedidos são encriptados via HTTPS; nenhum dado de pagamento sensível é armazenado.
</details>

<details>
<summary><strong>O addon da ifthenpay para o WPForms aceita WEBHOOKS (Callbacks)?</strong></summary>
Sim! O addon da ifthenpay para o WPForms a partir da versão 2.0.0, e versões futuras, aceita webhooks (callbacks).
</details>

## Serviços Externos

Este plugin integra-se com a plataforma de pagamentos ifthenpay para processar pagamentos das submissões do WPForms. A ifthenpay é um serviço de terceiros que disponibiliza processamento seguro de pagamentos por cartão, carteiras digitais e transferências bancárias locais.

- **WPForms**
  - **O que é e para que é utilizado**: Um plugin de criação de formulários utilizado para criar formulários de pagamento. Este plugin estende as suas capacidades de pagamento.

- **Backoffice e Integrações ifthenpay**
  - **O que é e para que é utilizado**: O Backoffice ifthenpay é o painel do comerciante utilizado para gerir integrações e configurações de pagamento. O plugin utiliza a API da ifthenpay para gerar links de pagamento e validar transações.
  - **Que dados são enviados e quando**:
    - Durante a configuração: Backoffice Key e Gateway Key para autenticação e obtenção da configuração.
    - Durante o processamento do pagamento: ID de referência da encomenda, montante, descrição, contas de métodos de pagamento ativos, URLs de retorno de sucesso/erro/cancelamento, idioma e, opcionalmente, o método de pagamento selecionado, o e-mail do cliente, o nome do cliente e dados dos campos do formulário.
    - Durante o registo do webhook: a Gateway Key e o URL de callback deste site, para que a ifthenpay possa notificar o site diretamente quando um pagamento é concluído.
    - Durante os pedidos de ativação de método de pagamento: quando um administrador solicita a ativação de um novo método de pagamento em `WPForms → Definições → Pagamentos`, é enviado um e-mail para o suporte da ifthenpay (suporte@ifthenpay.com) contendo a Backoffice Key, a Gateway Key, o método de pagamento solicitado, o endereço de e-mail do administrador, o URL do site, o nome do site, a versão do WordPress, a versão do WPForms e a versão do plugin.
    - Durante os callbacks: Estado do pagamento e método de pagamento.
  - **Contrato de Licença de Utilizador Final (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Política de Privacidade**: [Política de Privacidade](https://ifthenpay.com/politica-de-privacidade/)

Todos os pedidos de rede são realizados do lado do servidor via HTTPS. As credenciais sensíveis são armazenadas de forma segura e não são publicamente expostas. Não são armazenados dados em bruto de cartão ou bancários.

## Capturas de Ecrã

Seguem-se capturas de ecrã que demonstram as principais funcionalidades e interfaces do plugin:

1. **(Apenas Admin) Sincronização do Backoffice em WPForms Settings Payments**
   ![Definições do Backoffice](.wordpress-org/screenshot-1.png)
2. **(Apenas Admin) Página de administração do WPForms (Criação/Edição de Formulário -> Pagamentos)**
   ![Definições do Gateway](.wordpress-org/screenshot-2.png)
3. **(Apenas Admin) Adicionar o campo de Pagamento ifthenpay ao formulário selecionado**
   ![Adicionar Campo ao Formulário](.wordpress-org/screenshot-3.png)
4. **(Experiência do Cliente) A apresentação do campo Gateway de Pagamento varia consoante as definições do WPForms**
   ![Apresentação do Campo](.wordpress-org/screenshot-4.png)
5. **(Experiência do Cliente) Página de Pagamento Segura da ifthenpay**
   ![Página de Pagamento](.wordpress-org/screenshot-5.png)
6. **(Experiência do Cliente) Mensagem de Pagamento (pago, pendente, cancelado ou falhado)**
   ![Apresentação da Mensagem de Pagamento](.wordpress-org/screenshot-6.png)
7. **(Apenas Admin) Detalhes do Pagamento**
   ![Detalhes do Pagamento](.wordpress-org/screenshot-7.png)
8. **(Apenas Admin) Entradas de Pagamento**
   ![Entradas de Pagamento](.wordpress-org/screenshot-8.png)

## Suporte

Para assistência, utilize o [fórum de suporte do WordPress.org](https://wordpress.org/support):

Verificações prévias:

- Método de pagamento ativado na Chave de Gateway E mapeado para a Integração
- A executar as versões atuais recomendadas de WordPress, PHP e WPForms

Helpdesk comercial disponível (sem necessidade de e-mail direto): [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)

- **Suporte ifthenpay**: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
- **Documentação do WPForms**: [WPForms docs](https://wpforms.com/docs/)
