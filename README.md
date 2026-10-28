
# ClicToPay Monétique Tunisie (SPS-SMT) – Prestashop 1.7.7 à 9.x

![logo ClicToPay](logo.png) 

ClicToPay (SMT-SPS) est un module pour **Prestashop 1.7.7 à 9.x** de paiement en ligne destiné pour les commerçants en Tunisie.

## Caractéristiques et fonctionnalités du module ClicToPay (SMT-SPS) :

> - Simple, performant.
> - Répond aux normes de développements de Prestashop 1.7.x
> - Accepte les paiements multidevises \*\*selon votre accord avec la banque
> - Accepte les paiements de différents cartes (locale et internationale) \*\*selon votre accord avec la banque
> - Enregistré sous la licence BSD.
> - Développé par  **Oxygène Technologies**

## Installation

> 1. Télécharger le « . **zip**  » du module depuis GitHub «  **ClicToPay-SMT-master.zip**  »
> 2. Décompressez le fichier «  **.zip**  » «  **ClicToPay-SMT-master.zip** »
> 3. Renommez le dossier «  **ClicToPay-SMT-master** » en «  **clictopay** » ( **Attention tout est en minuscule !** ).
> 4. Compressez le dossier renommé «  **clictopay**  » en fichier «  **.zip**  »
> 5. Uploader le fichier «  **clictopay.zip ** » depuis le back office de Prestashop, menu Modules.
> 6. Installez le module «  **ClicToPay SMT »** depuis le back office de Prestashop, menu Modules.

## Configuration

> 1. Cliquez sur le bouton « **Configurer** » du module « **ClicToPay SMT** »
> 2. Remplissez « **Api-User Login** » et « **Api-User Password** » fournis par ClicToPay
> 3. Choisissez la langue de la page de paiement
> 4. Activez ou désactivez le mode « **Sandbox** » (test.clictopay.com / ipay.clictopay.com)
> 5. Enregistrez la configuration.

Aucune URL n'est à déclarer chez ClicToPay : `returnUrl` et `failUrl` sont envoyées à chaque appel `register.do`.

## Fonctionnement d'un paiement

> 1. Le client choisit ClicToPay : le contrôleur `redirect` appelle `register.do` et le redirige vers `formUrl`.
> 2. Après la saisie de la carte, ClicToPay renvoie le client vers le contrôleur `return` (même URL en succès et en échec, protégée par la clé du panier).
> 3. Le contrôleur `return` appelle `getOrderStatusExtended.do`. La commande n'est créée que si `orderStatus = 2`. Sinon le client revient au tunnel de commande avec le motif du refus, et son panier reste disponible.

## Tests d'intégration

La page de configuration contient un écran « **ClicToPay integration tests** » qui exécute depuis la boutique les cas de la grille de validation (enregistrement valide, montant manquant, numéro de commande en double, orderId inconnu, consultation d'un orderId) et affiche le JSON brut. Avec l'option « **Debug log** », chaque appel est aussi enregistré dans *Paramètres avancés > Logs* (mot de passe masqué).

## Licence

Ce Module est sous la licence BSD. Développé par  Oxygène Technologies https://oxygene-tech.com

Voir [LICENCE](https://github.com/agencep/ClicToPay-SMT/blob/master/LICENSE.txt)
=======
# SMT-Prestashop
Module Click To Pay Prestashop 1.7, 8, 9
>>>>>>> 958fa688611728a286621093077cdef7a07df4c9
