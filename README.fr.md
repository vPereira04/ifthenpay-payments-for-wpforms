# ifthenpay | Paiements pour WPForms

[English](README.md) | [Português](README.pt.md) | [Español](README.es.md) | **Français**

Ajoute les méthodes de paiement ifthenpay à WPForms : cartes, portefeuilles électroniques et moyens de paiement locaux ; prend en charge les paiements uniques et sécurisés via pay-by-link.

---

## Table des Matières

- [Description](#description)
- [Fonctionnalités Principales](#fonctionnalités-principales)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration du Formulaire](#configuration-du-formulaire)
- [Utiliser ifthenpay Avec une Autre Passerelle de Paiement](#utiliser-ifthenpay-avec-une-autre-passerelle-de-paiement)
- [Questions Fréquentes](#questions-fréquentes)
- [Services Externes](#services-externes)
- [Captures d'Écran](#captures-décran)
- [Support](#support)

## Description

Ce plugin intègre la passerelle de paiement ifthenpay à WPForms afin de permettre la collecte de paiements directement depuis vos formulaires. Les paiements sont traités via un système sécurisé de pay-by-link, garantissant qu'aucune donnée sensible de carte ou donnée bancaire n'est stockée sur votre site web. Les clients peuvent effectuer le paiement avec la méthode de leur choix via une page de paiement sécurisée. Après l'envoi d'un formulaire, les utilisateurs sont redirigés vers la page de paiement sécurisée hébergée par ifthenpay pour finaliser la transaction ; ifthenpay envoie ensuite une notification (callback) côté serveur pour mettre à jour automatiquement le statut du paiement.

### En termes simples, vous obtenez :

- Des paiements uniques directement depuis WPForms
- La prise en charge des coupons et le calcul automatique des totaux
- Un backoffice commerçant (ventes de base) sur le web et sur mobile
- Des confirmations de paiement automatiques et sécurisées (aucun numéro de carte stocké)

Tous les réglages se font dans WPForms et dans votre Backoffice ifthenpay. Le plugin est conçu pour que les propriétaires de sites puissent gérer les paiements sans connaissances techniques approfondies.

## Fonctionnalités Principales

1. Intégration complète avec le flux de paiement de WPForms Lite et Pro
2. Transactions sécurisées
3. Confirmation automatique du paiement
4. Prise en charge de plusieurs méthodes de paiement (cartes, portefeuilles électroniques, virements)
5. Prise en charge des coupons et remises via WPForms
6. Redirection sécurisée en pleine page vers la page de paiement hébergée par ifthenpay
7. Statut du paiement en temps réel dans les entrées WPForms
8. Prise en charge multilingue (EN, ES, FR, PT)
9. Sécurité avant tout (aucune donnée de carte stockée)

## Prérequis

- Un compte marchand ifthenpay actif — [inscrivez-vous ici](https://ifthenpay.com/aderir/) pour obtenir vos identifiants.
- Une Clé de Passerelle WPForms (à demander au support/helpdesk d'ifthenpay).
- Les méthodes de paiement que vous souhaitez activer sur cette Clé de Passerelle (notre équipe de helpdesk vous guidera).
- WordPress 6.5+ et PHP 8.2+, avec WPForms installé et activé.
- HTTPS (SSL) activé sur votre site.

## Installation

1. **Installer :** Téléversez le fichier zip du plugin via `Extensions → Ajouter → Téléverser`, ou installez-le depuis WordPress.org, puis activez-le.
2. **Identifiants :** Assurez-vous que votre compte ifthenpay dispose d'une Clé de Passerelle WPForms active avec les méthodes de paiement souhaitées activées.
3. **Configuration :** Allez dans `WPForms → Réglages → Paiements` et saisissez votre Backoffice Key.
4. **Configuration du formulaire :** `Créer/Modifier un formulaire → onglet Paiements → Ajouter le champ Ifthenpay à votre formulaire → activer « ifthenpay | Payment Gateway »` et sélectionnez une Clé de Passerelle. Choisissez ensuite les méthodes de paiement à activer parmi celles disponibles sur votre passerelle, puis définissez votre méthode de paiement par défaut. Enfin, ajoutez une description de paiement, qui sera affichée sur la page de paiement ifthenpay pour toutes les transactions.

## Utiliser ifthenpay Avec une Autre Passerelle de Paiement

WPForms n'autorise qu'une seule méthode de paiement active par soumission. Si un formulaire présente à la fois le champ ifthenpay et le champ d'une autre passerelle de paiement (PayPal, Stripe, Square ou Authorize.Net) visibles en même temps, le champ ifthenpay se masque automatiquement — logo, méthodes de paiement et bouton « Payer maintenant » — afin que les clients ne voient jamais qu'une seule option de paiement réellement fonctionnelle, plutôt qu'un bouton qui échouerait ou les débiterait deux fois.

- Le champ ifthenpay réapparaît automatiquement si le champ de l'autre passerelle redevient masqué (par exemple via la logique conditionnelle de WPForms), sans nécessiter de rechargement de la page.
- Cela ne s'applique qu'aux champs de passerelle réellement visibles sur le formulaire. Un champ de passerelle présent mais masqué par une logique conditionnelle ne déclenche pas ce comportement.
- Les champs natifs de WPForms tels que Total, Coupon et les éléments de paiement à choix unique/multiple/case à cocher/liste déroulante ne sont jamais considérés comme des passerelles concurrentes — seuls les champs PayPal, Stripe, Square et Authorize.Net le sont.

## Questions Fréquentes

<details>
<summary><strong>Ce plugin nécessite-t-il WPForms ?</strong></summary>
Oui. WPForms doit être installé et actif pour utiliser ce plugin.
</details>

<details>
<summary><strong>Prend-il en charge les paiements récurrents ?</strong></summary>
Non. Cette version ne prend en charge que les paiements uniques via pay-by-link.
</details>

<details>
<summary><strong>Les données de paiement sont-elles stockées ?</strong></summary>
Non. Le plugin ne stocke pas les numéros de carte ni les coordonnées bancaires complètes. Seules les références minimales nécessaires au rapprochement du paiement sont conservées.
</details>

<details>
<summary><strong>Prend-il en charge les coupons WPForms ?</strong></summary>
Oui. Les champs de coupon WPForms sont entièrement pris en charge et les remises sont calculées automatiquement.
</details>

<details>
<summary><strong>Quelles méthodes de paiement sont prises en charge ?</strong></summary>
Toute méthode ifthenpay associée à votre Clé de Passerelle (par exemple, Multibanco, MB WAY, Payshop, Carte de Crédit, Google Pay, Apple Pay, Pix).
</details>

<details>
<summary><strong>Comment fonctionne le processus de paiement ?</strong></summary>
Après l'envoi du formulaire, les utilisateurs sont redirigés vers une page de paiement sécurisée hébergée par ifthenpay. Une fois le paiement effectué, le statut est mis à jour automatiquement via un callback.
</details>

<details>
<summary><strong>Que se passe-t-il en cas d'échec d'un paiement ?</strong></summary>
L'entrée est marquée comme Échouée. Les utilisateurs peuvent retenter le paiement selon votre configuration.
</details>

<details>
<summary><strong>Puis-je personnaliser l'expérience de paiement ?</strong></summary>
Oui. Vous pouvez configurer le libellé du bouton, la description et le style directement dans WPForms.
</details>

<details>
<summary><strong>Que se passe-t-il si mon formulaire contient aussi un autre champ de passerelle de paiement (PayPal, Stripe, Square, Authorize.Net) ?</strong></summary>
Le champ ifthenpay se masque automatiquement tant que le champ de l'autre passerelle est visible, afin que les clients ne voient jamais deux options de paiement actives en même temps. Voir <a href="#utiliser-ifthenpay-avec-une-autre-passerelle-de-paiement">Utiliser ifthenpay Avec une Autre Passerelle de Paiement</a>.
</details>

<details>
<summary><strong>Existe-t-il un environnement de test (sandbox) ?</strong></summary>
ifthenpay peut fournir des entités de test ; si ce n'est pas possible, effectuez un test réel de faible montant.
</details>

<details>
<summary><strong>Quel est le niveau de sécurité de l'intégration ?</strong></summary>
Les requêtes sont chiffrées via HTTPS ; aucune donnée de paiement sensible n'est stockée.
</details>

<details>
<summary><strong>Le module complémentaire ifthenpay pour WPForms accepte-t-il les WEBHOOKS (Callbacks) ?</strong></summary>
Oui ! Le module complémentaire ifthenpay pour WPForms, à partir de la version 2.0.0 et des suivantes, accepte les webhooks (callbacks).
</details>

## Services Externes

Ce plugin s'intègre à la plateforme de paiement ifthenpay pour traiter les paiements des soumissions WPForms. ifthenpay est un service tiers qui fournit un traitement sécurisé des paiements par carte, portefeuille électronique et virement bancaire local.

- **WPForms**
  - **Ce que c'est et à quoi ça sert** : Un plugin de création de formulaires utilisé pour créer des formulaires de paiement. Ce plugin étend ses capacités de paiement.

- **Backoffice et Intégrations ifthenpay**
  - **Ce que c'est et à quoi ça sert** : Le Backoffice ifthenpay est le tableau de bord marchand utilisé pour gérer les intégrations et les configurations de paiement. Le plugin utilise l'API ifthenpay pour générer des liens de paiement et valider les transactions.
  - **Quelles données sont envoyées et quand** :
    - Lors de la configuration : Backoffice Key et Gateway Key pour l'authentification et la récupération de la configuration.
    - Lors du traitement du paiement : identifiant de référence de commande, montant, description, comptes des méthodes de paiement activées, URL de retour succès/erreur/annulation, langue et, éventuellement, la méthode de paiement sélectionnée, l'e-mail du client, le nom du client et les données des champs du formulaire.
    - Lors de l'enregistrement du webhook : la Gateway Key et l'URL de callback de ce site, afin qu'ifthenpay puisse notifier directement le site lorsqu'un paiement est finalisé.
    - Lors des demandes d'activation d'une méthode de paiement : lorsqu'un administrateur demande l'activation d'une nouvelle méthode de paiement depuis `WPForms → Réglages → Paiements`, un e-mail est envoyé au support ifthenpay (suporte@ifthenpay.com) contenant la Backoffice Key, la Gateway Key, la méthode de paiement demandée, l'adresse e-mail de l'administrateur, l'URL du site, le nom du site, la version de WordPress, la version de WPForms et la version du plugin.
    - Lors des callbacks : statut du paiement et méthode de paiement.
  - **Contrat de Licence Utilisateur Final (CLUF/EULA)** : [EULA](https://ifthenpay.com/eula/)
  - **Politique de Confidentialité** : [Politique de Confidentialité](https://ifthenpay.com/politica-de-privacidade/)

Toutes les requêtes réseau sont effectuées côté serveur via HTTPS. Les identifiants sensibles sont stockés en toute sécurité et ne sont pas exposés publiquement. Aucune donnée brute de carte ou bancaire n'est stockée.

## Captures d'Écran

Voici des captures d'écran illustrant les principales fonctionnalités et interfaces du plugin :

1. **(Admin uniquement) Synchronisation du Backoffice dans WPForms Settings Payments**
   ![Réglages du Backoffice](.wordpress-org/screenshot-1.png)
2. **(Admin uniquement) Page d'administration de WPForms (Création/Modification de Formulaire -> Paiements)**
   ![Réglages de la Passerelle](.wordpress-org/screenshot-2.png)
3. **(Admin uniquement) Ajout du champ de Paiement ifthenpay au formulaire sélectionné**
   ![Ajout du Champ au Formulaire](.wordpress-org/screenshot-3.png)
4. **(Expérience Client) L'affichage du champ Passerelle de Paiement varie selon les réglages de WPForms**
   ![Affichage du Champ](.wordpress-org/screenshot-4.png)
5. **(Expérience Client) Page de Paiement Sécurisée ifthenpay**
   ![Page de Paiement](.wordpress-org/screenshot-5.png)
6. **(Expérience Client) Message de Paiement (payé, en attente, annulé ou échoué)**
   ![Affichage du Message de Paiement](.wordpress-org/screenshot-6.png)
7. **(Admin uniquement) Détails du Paiement**
   ![Détails du Paiement](.wordpress-org/screenshot-7.png)
8. **(Admin uniquement) Entrées de Paiement**
   ![Entrées de Paiement](.wordpress-org/screenshot-8.png)

## Support

Pour obtenir de l'aide, utilisez le [forum de support WordPress.org](https://wordpress.org/support) :

Vérifications préalables :

- Méthode de paiement activée sur la Clé de Passerelle ET associée à l'Intégration
- Exécution des versions actuelles recommandées de WordPress, PHP et WPForms

Helpdesk commercial disponible (aucun e-mail direct requis) : [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)

- **Support ifthenpay** : [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
- **Documentation WPForms** : [WPForms docs](https://wpforms.com/docs/)
