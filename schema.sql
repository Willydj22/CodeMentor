-- Esquema de CodeMentor (inferido de las consultas del código).
-- Revísalo contra tu base real antes de usarlo en producción.
CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS chats (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT NOT NULL,
  titulo         VARCHAR(255) NOT NULL DEFAULT 'Nuevo chat',
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS mensajes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  chat_id    INT NOT NULL,
  rol        VARCHAR(20) NOT NULL,
  contenido  MEDIUMTEXT NOT NULL,
  creado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS conocimiento (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  pregunta           TEXT,
  concepto           VARCHAR(255),
  categoria          VARCHAR(100),
  descripcion        MEDIUMTEXT,
  sintaxis           MEDIUMTEXT,
  ejemplo            MEDIUMTEXT,
  errores            MEDIUMTEXT,
  buenas_practicas   MEDIUMTEXT,
  palabras_clave     TEXT,
  nivel_dificultad   VARCHAR(30),
  estado             VARCHAR(30),
  fecha_actualizacion DATETIME NULL,
  INDEX (concepto)
) CHARACTER SET utf8mb4;
