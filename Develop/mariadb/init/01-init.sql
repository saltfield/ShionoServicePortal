-- Applied only on first volume initialization.
-- Default charset/collation also come from my.cnf.

CREATE DATABASE IF NOT EXISTS ssp
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_uca1400_ai_ci;

CREATE DATABASE IF NOT EXISTS ssp_testing
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_uca1400_ai_ci;

GRANT ALL PRIVILEGES ON ssp.* TO 'ssp'@'%';
GRANT ALL PRIVILEGES ON ssp_testing.* TO 'ssp'@'%';
FLUSH PRIVILEGES;
