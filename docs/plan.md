L'objectif est de créer une application domotique responsable de la gestion électrique de ma maison.

1. Je veux que la charge de ma voiture électrique se lance automatiquement pendant les heures creuses
2. Je veux avoir des statistiques sur ma production solaire à la minute (kW / A)
3. Je veux avoir des statistiques sur ma consommation instatanné (kW / A) à la minute
4. Je veux pouvoir lancer une charge quand j'injecte sur le réseau (au dessus de 6A qui est le minimum de charge de la voiture). La charge doit s'arrêter automatiquement si je recommence à consommer
5. Je veux pouvoir faire du délestage, diminuer la charge de la voiture si ma maison commence à consommer trop.

Mon installation électrique est en tri-phasé.

Ma borne de recharge est une borne Lektrico, package Python a convertir en PHP pour les appels API :

https://github.com/Lektrico/lektricowifi

J'ai des pinces ampermétriques sur mon arrivée électrique, accessible via cette API :

`http get http://192.168.68.121/rpc/Meter_info.Get`

Ma production solaire a un contrôleur Envoy, la doc est dans @docs/envoy-production-solaire-api-doc.pdf il faut pouvoir générer le token et query l'API.
