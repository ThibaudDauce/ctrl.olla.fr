# ctrl.olla.fr — Gestion électrique domotique

Application Laravel de gestion électrique : charge véhicule électrique, monitoring production solaire et consommation, délestage automatique.

Installation triphasée, abonnement 5 kVA par phase.

---

## Devices

### Borne de recharge Lektrico (`LEKTRICO_HOST`)

API HTTP JSON-RPC sur `http://<host>/rpc`.

**Lecture (GET)** :
- `GET /rpc/charger_info.get` → état de charge (`extended_charger_state`), `instant_power` (W), `currents[3]`, `voltages[3]`, `session_energy` (kWh), `charging_time` (s), `temperature`
- `GET /rpc/app_config.get` → `user_current`, `install_current`
- `GET /rpc/dynamic_current.get` → `dynamic_current`, `relay_mode`

**Commandes (POST à `/rpc`)** :
- `charge.start` avec `params: {"tag": "ctrl"}` → démarre la charge
- `charge.stop` → arrête la charge
- `app_config.set` avec `params: {"config_key": "user_current", "config_value": <amps>}` → règle l'ampérage (6-32A)

Le body POST suit le format : `{"src": "ctrl", "id": <rand_8_digits>, "method": "...", "params": {...}}`

États de la borne (`extended_charger_state`) :
- `A` = disponible, `B` = véhicule connecté, `C`/`D` = en charge, `E`/`F` = erreur
- `B_PAUSE` = en pause, `B_AUTH` = attente auth, `B_SCHEDULER` = pausé par scheduler

Détection triphasé : si `currents[1]` et `currents[2]` > 0 pendant la charge → triphasé.

### Pinces ampèremétriques Shelly (`METER_HOST`)

- `GET http://<host>/rpc/Meter_info.Get` → `current[3]`, `voltage[3]`, `active_p[3]` (W par phase), `power_factor[3]`
- Valeurs positives = consommation depuis le réseau, négatives = injection vers le réseau

### Contrôleur solaire Enphase Envoy (`ENVOY_HOST`)

API HTTPS locale avec certificat auto-signé (vérification SSL désactivée).

**Authentification** : token JWT valide 1 an, obtenu en 2 étapes :
1. `POST http://enlighten.enphaseenergy.com/login/login.json` avec `user[email]` + `user[password]` → `session_id`
2. `POST http://entrez.enphaseenergy.com/tokens` avec `{"session_id": "...", "serial_num": "...", "username": "..."}` → token

Le token est passé en header `Authorization: Bearer <token>` sur toutes les requêtes locales.

**Endpoints** :
- `GET /api/v1/production` → `wattsNow` (W instantanés), `wattHoursToday`, `wattHoursLifetime` — le plus simple, suffisant pour le besoin
- `GET /ivp/meters/readings` → données détaillées par phase (voltage, current, power factor, énergie cumulée) — utile si on veut la production par phase

Les données de production se rafraîchissent toutes les ~5 minutes côté Envoy.

### Notifications SMS Free (`FREE_SMS_USER`, `FREE_SMS_KEY`)

`GET https://smsapi.free-mobile.fr/sendmsg?user=<user>&pass=<key>&msg=<url_encoded_msg>`

---

## Phase 1 — Collecte, charge heures creuses, charge solaire, délestage

### 1.1 Clients API (app/Support/)

Trois classes simples utilisant `Http` facade de Laravel :

**`LektricoClient`** — lecture info + commandes start/stop/setCurrent
**`MeterClient`** — lecture des pinces ampèremétriques
**`EnvoyClient`** — authentification token + lecture production

Chaque client prend son host depuis `config/services.php` (alimenté par `.env`).

`EnvoyClient` utilise un token stocké en DB (table `settings`, clé `envoy_token` + `envoy_token_expires_at`). Le token est valide 1 an, pas de renouvellement automatique — pas de mot de passe Enphase stocké sur le serveur. Commande artisan `app:envoy-token` qui demande interactivement email/password, génère le token via l'API Enphase, et le stocke. SMS de rappel envoyé 7 jours avant expiration (vérifié par le healthcheck).

### 1.2 Collecte des données (cron chaque minute)

**Commande artisan `app:collect-metrics`** exécutée chaque minute via le scheduler.

Query les 3 devices en parallèle, stocke en DB dans une table `metrics` :

```
metrics
├── id
├── timestamp (indexé, unique à la minute)
├── meter_power_total (W, float) — consommation totale réseau
├── meter_power_l1 (W)
├── meter_power_l2 (W)
├── meter_power_l3 (W)
├── solar_power (W, float) — production solaire instantanée
├── charger_state (string) — état brut de la borne
├── charger_power (W, float) — puissance de charge instantanée
├── charger_current (A, int) — ampérage demandé
├── charger_current_l1 (A, float)
├── charger_current_l2 (A, float)
├── charger_current_l3 (A, float)
└── created_at
```

Les valeurs du meter sont signées : positif = consommation, négatif = injection.

En cas d'erreur sur un device, log l'erreur, continue avec les autres, et SMS d'alerte (throttled à 1/heure par device). Si le meter ou la borne est injoignable, la commande `app:manage-charging` ne prend aucune décision automatique (pas de start/stop/ajustement) pour éviter d'agir sur des données incomplètes.

### 1.3 Charge heures creuses automatique

**Commande artisan `app:manage-charging`** exécutée chaque minute via le scheduler.

Logique heures creuses (configurable via `.env` : `OFF_PEAK_START=22:15`, `OFF_PEAK_END=05:55`) :
- Si on entre dans la plage heures creuses ET la borne est en état `B` (connecté) ou `B_PAUSE` → `charge.start`
- Si on sort de la plage heures creuses ET la borne charge → `charge.stop` (sauf si charge solaire active, cf. 1.4)

### 1.4 Charge solaire (surplus d'injection)

Intégrée dans la même commande `app:manage-charging`.

Calcul du surplus disponible : si `meter_power_total` est négatif (injection), le surplus = `abs(meter_power_total)`.

Conversion en ampères disponibles : `surplus_amps = surplus_watts / 230` (monophasé) ou `surplus_watts / (230 * 3)` (triphasé, par phase).

**Démarrage** : si surplus ≥ 6A (minimum de charge) depuis ≥ 3 minutes consécutives ET borne en état connectable → démarrer à 6A.

**Ajustement** : chaque minute, recalculer le courant optimal en fonction du surplus. Ajuster `user_current` par paliers de 1A, en lissant sur les 3 dernières minutes pour éviter l'oscillation.

**Arrêt** : si la consommation redevient positive (on tire du réseau) depuis ≥ 2 minutes → `charge.stop`.

### 1.5 Délestage

Intégré dans la même commande `app:manage-charging`.

Contrainte : 5 kVA par phase → ~21.7A par phase max.

Si pendant la charge, une phase dépasse un seuil configurable (`PHASE_MAX_AMPS=20`) :
1. Réduire `user_current` de 1A
2. Si déjà à 6A et toujours en surcharge → `charge.stop`
3. SMS d'alerte si arrêt forcé pour délestage

Le délestage est prioritaire sur tout le reste (heures creuses et solaire).

### 1.6 Suivi des sessions de charge

Table `charging_sessions` pour tracker chaque session :

```
charging_sessions
├── id
├── started_at
├── ended_at (nullable)
├── mode (enum: off_peak, solar, manual)
├── energy_kwh (float, mis à jour via session_energy de la borne)
├── is_three_phase (bool, nullable — déterminé après détection)
├── max_current (int, ampérage max atteint pendant la session)
└── created_at / updated_at
```

### 1.7 Dashboard (page unique `/`)

Route `/` → composant Livewire `Dashboard`, polling toutes les 5 secondes.

**Graphique journalier** (composant `<flux:chart>`) :
- Axe X : heures de la journée (0h-24h), un point par minute depuis la table `metrics`
- Courbe consommation réseau (W) — valeurs du meter
- Courbe production solaire (W) — valeurs Envoy
- Courbe charge voiture (W) — puissance de charge
- Zone colorée entre production et consommation pour visualiser le surplus/déficit

**Bloc état de charge** (visible uniquement quand la borne est en état `C` = en charge) :
- Puissance actuelle (ex: "3.2 kW")
- Ampérage actuel et max (ex: "14A / 16A demandés")
- Raison de la limitation actuelle : badge indiquant pourquoi la puissance est ce qu'elle est :
  - "Heures creuses" — charge à pleine puissance pendant les heures creuses
  - "Solaire : 8A disponibles" — limité par le surplus solaire
  - "Délestage : phase L2 surchargée" — réduit pour protéger l'installation
  - "Manuel" — ampérage réglé manuellement
- Durée de charge et énergie de la session (kWh)
- Mono/triphasé si détecté

**Contrôles manuels** :
- Bouton start/stop (visible selon l'état de la borne)
- Slider ampérage (6-32A) pour override manuel

### 1.8 SMS & healthcheck

Classe `SmsNotifier` qui envoie via l'API Free Mobile.

Notifications envoyées :
- Erreur de communication avec un device (throttled 1/heure)
- Arrêt forcé par délestage
- Le cron `app:collect-metrics` ne tourne plus depuis > 5 minutes (via un healthcheck séparé `app:healthcheck` lancé toutes les 5 minutes qui vérifie que la dernière métrique date de < 3 minutes)

---

## Phase 2 — Authentification WebAuthn

Système d'auth sans utilisateurs classiques, basé uniquement sur des devices WebAuthn.

### Table `webauthn_credentials`

```
webauthn_credentials
├── id (string, credential ID)
├── name (string, nom du device donné à l'enregistrement)
├── public_key (binary)
├── approved (bool, default false)
├── last_used_at (nullable)
└── created_at / updated_at
```

### Flux d'authentification

1. Toutes les routes (sauf `/register`) protégées par un middleware
2. Le middleware vérifie la session pour un `webauthn_credential_id` valide
3. Si pas authentifié → page de login qui lance le challenge WebAuthn directement (pas de formulaire, juste le prompt du navigateur)
4. L'utilisateur valide (empreinte, Face ID, YubiKey...)
5. Le credential est vérifié côté serveur contre la table `webauthn_credentials` (doit être `approved = true`)
6. Session créée, redirect vers `/`

### Enregistrement de nouveaux devices

1. `GET /register` → page qui permet d'enregistrer un nouveau device WebAuthn
2. Le credential est stocké avec `approved = false`
3. Commande artisan `app:approve-device {id}` (ou `--latest`) pour approuver
4. Tant que non approuvé, le device ne peut pas s'authentifier

---

## Phase 3 — Détection triphasé et facturation

### Détection automatique du mode de charge

Après le démarrage d'une charge, attendre 2 minutes puis vérifier `currents[1]` et `currents[2]` depuis `charger_info.get` :
- Si les 3 phases ont du courant > 0.5A → triphasé
- Sinon → monophasé

Stocker le résultat dans `charging_sessions.is_three_phase`.

Si la borne est mono (`1p7k`), ce check est inutile — toujours monophasé. Le type de borne est détectable via `GET /rpc/Device_id.Get` (préfixe `1p7k` vs `3p22k`).

### Adaptation de la puissance en triphasé

En triphasé, la contrainte de 5 kVA/phase reste mais on répartit sur 3 phases :
- Courant max par phase = `(PHASE_MAX_AMPS - consommation_maison_phase) A`
- Le minimum des 3 phases donne le courant de charge max (la borne tire la même intensité sur chaque phase)

### Facturation des charges triphasé (= pas ma voiture)

Si `is_three_phase = true`, la session est considérée comme facturable.

Table `billing_records` :

```
billing_records
├── id
├── charging_session_id (FK)
├── energy_kwh (float)
├── price_per_kwh (float, configurable via .env)
├── total_price (float)
├── notes (nullable, text)
├── settled (bool, default false)
└── created_at / updated_at
```

À la fin d'une session triphasée, un `billing_record` est créé automatiquement. SMS envoyé avec le récapitulatif (kWh, montant).

Page `/billing` listant les charges facturables avec possibilité de marquer comme réglé.
