-- @doc-file
-- @doc-id live_app.setup_sql
-- @doc-kind schema
-- @doc-purpose Beschreibt das Grundschema und Initialdaten fuer die relationale Anwendung.
-- @doc-entry Wird bei Einrichtung oder manueller DB-Vorbereitung ausgefuehrt.
-- @doc-input SQL-Client, leere oder vorbereitete Datenbank.
-- @doc-output Angelegte Tabellen, Indizes und Startdaten.
-- @doc-side-effects Erzeugt oder veraendert Datenbankstrukturen und Inhalte.
-- @doc-note Dieses Schema ist fuer die initiale Einrichtung gedacht und wird nur einmal ausgefuehrt.
-- @doc-note Bei abweichender Zielumgebung muss insbesondere der Datenbankname angepasst werden.
-- @doc-note Historische Request-Metadaten werden nicht mehr gespeichert; Altspalten entfernt das Skript scripts/privacy_cleanup.php.

CREATE DATABASE IF NOT EXISTS dlrg_schwimmen
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE dlrg_schwimmen;

CREATE TABLE IF NOT EXISTS teams (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_team_name (name)
)
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teilnehmer (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL,
  vorname VARCHAR(100) NOT NULL,
  nachname VARCHAR(100) NOT NULL,
  volljaehrig TINYINT(1) NOT NULL,
  geschlecht VARCHAR(20) NOT NULL,
  team_id INT DEFAULT NULL,
  teamname VARCHAR(150) DEFAULT NULL,
  teilnahmebedingungen_akzeptiert TINYINT(1) NOT NULL,
  dsgvo_akzeptiert TINYINT(1) NOT NULL,
  bild_ton_akzeptiert TINYINT(1) NOT NULL,
  bezahlt TINYINT(1) NOT NULL DEFAULT 0,
  bezahlt_am TIMESTAMP NULL DEFAULT NULL,
  registriert_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  startnummer INT DEFAULT NULL,
  UNIQUE KEY uniq_teilnehmer_code (code),
  KEY idx_teilnehmer_bezahlt (bezahlt),
  KEY idx_teilnehmer_startnummer (startnummer),
  KEY idx_teilnehmer_team_id (team_id),
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
)
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS startnummern (
  nummer INT PRIMARY KEY,
  status ENUM('frei', 'reserviert', 'vergeben') NOT NULL DEFAULT 'frei',
  reserviert_fuer VARCHAR(150) DEFAULT NULL,
  team_id INT DEFAULT NULL,
  teilnehmer_id INT DEFAULT NULL,
  KEY idx_status (status),
  KEY idx_team_id (team_id),
  KEY idx_teilnehmer_id (teilnehmer_id),
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
  FOREIGN KEY (teilnehmer_id) REFERENCES teilnehmer(id) ON DELETE SET NULL
)
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','cashier','orga','viewer') NOT NULL DEFAULT 'viewer',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_username (username)
)
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lane_timeframes (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lane_timeframes_active_sort (active, sort_order, start_at, end_at),
  KEY idx_lane_timeframes_start_end (start_at, end_at)
)
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
