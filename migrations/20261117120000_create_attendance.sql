-- Assiduidade / Registo de Ponto (porta de intranet/assiduidade.php da intranet
-- legacy, alargada com varios horarios, ausencias, ferias e feriados).
-- Ver docs/assiduidade.md.

-- Horarios do escritorio (podem existir varios; um e o horario por omissao).
-- tolerance_minutes: atraso tolerado na entrada antes de contar como atraso
-- (setting 'tolerancia' no legacy).
CREATE TABLE IF NOT EXISTS attendance_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    tolerance_minutes INT UNSIGNED NOT NULL DEFAULT 15,
    color VARCHAR(7) NOT NULL DEFAULT '#3498DB',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Periodos por dia da semana (1 = segunda ... 7 = domingo). Sem linha, ou com
-- os dois periodos vazios, o dia e de descanso. Manha = picagens 1/2,
-- tarde = picagens 3/4 (como no legacy).
CREATE TABLE IF NOT EXISTS attendance_schedule_days (
    schedule_id INT NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    morning_start TIME NULL,
    morning_end TIME NULL,
    afternoon_start TIME NULL,
    afternoon_end TIME NULL,
    PRIMARY KEY (schedule_id, weekday),
    CONSTRAINT fk_attendance_schedule_days_schedule
        FOREIGN KEY (schedule_id) REFERENCES attendance_schedules(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuracao por colaborador: horario atribuido (NULL = horario por
-- omissao), se regista ponto e dias de ferias anuais.
CREATE TABLE IF NOT EXISTS attendance_user_settings (
    user_id INT NOT NULL PRIMARY KEY,
    schedule_id INT NULL,
    tracked TINYINT(1) NOT NULL DEFAULT 1,
    vacation_days DECIMAL(5,1) NOT NULL DEFAULT 22.0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_user_settings_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_user_settings_schedule
        FOREIGN KEY (schedule_id) REFERENCES attendance_schedules(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dias de ferias transitados do ano anterior (opcional, por ano).
CREATE TABLE IF NOT EXISTS attendance_vacation_carryover (
    user_id INT NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    days DECIMAL(5,1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, year),
    CONSTRAINT fk_attendance_vacation_carryover_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Picagens (tabela 'assiduidade' do legacy). Uma por colaborador, dia e
-- periodo: 1 entrada manha, 2 saida manha, 3 entrada tarde, 4 saida tarde.
-- source: web (botao Registar), manual (corrigida por um gestor).
CREATE TABLE IF NOT EXISTS attendance_punches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    work_date DATE NOT NULL,
    period TINYINT UNSIGNED NOT NULL,
    punched_at DATETIME NOT NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'web',
    late_minutes INT NOT NULL DEFAULT 0,
    justification TEXT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_punches (user_id, work_date, period),
    KEY idx_attendance_punches_date (work_date),
    CONSTRAINT fk_attendance_punches_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipos de ausencia. is_vacation: desconta nos dias de ferias.
-- is_paid: so informativo (relatorios). requires_approval: o pedido do
-- colaborador fica pendente ate um gestor aprovar.
CREATE TABLE IF NOT EXISTS attendance_absence_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(120) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#26B99A',
    is_vacation TINYINT(1) NOT NULL DEFAULT 0,
    is_paid TINYINT(1) NOT NULL DEFAULT 1,
    requires_approval TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_attendance_absence_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ausencias / ferias. day_part: full (dia inteiro), morning, afternoon (so
-- para pedidos de um dia). status: pending, approved, rejected, cancelled.
CREATE TABLE IF NOT EXISTS attendance_absences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    day_part VARCHAR(10) NOT NULL DEFAULT 'full',
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    decision_note VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT NULL,
    decided_by INT NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_attendance_absences_user (user_id, start_date),
    KEY idx_attendance_absences_range (start_date, end_date),
    KEY idx_attendance_absences_status (status),
    CONSTRAINT fk_attendance_absences_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_absences_type
        FOREIGN KEY (type_id) REFERENCES attendance_absence_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feriados municipais, tolerancias de ponto e outros dias de encerramento.
-- Os feriados nacionais sao calculados no PHP (attendanceNationalHolidays).
-- day_part: full, morning, afternoon (ex.: tarde de 24/12).
CREATE TABLE IF NOT EXISTS attendance_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    name VARCHAR(120) NOT NULL,
    day_part VARCHAR(10) NOT NULL DEFAULT 'full',
    recurring TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attendance_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dados iniciais: horario do escritorio (segunda a sexta, 09:00-13:00 e
-- 14:00-18:00) e tipos de ausencia habituais.
INSERT INTO attendance_schedules (name, description, tolerance_minutes, color, is_default, is_active)
SELECT 'Horário geral', 'Segunda a sexta, 09:00-13:00 e 14:00-18:00', 15, '#3498DB', 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM attendance_schedules);

INSERT IGNORE INTO attendance_schedule_days (schedule_id, weekday, morning_start, morning_end, afternoon_start, afternoon_end)
SELECT s.id, d.weekday, '09:00:00', '13:00:00', '14:00:00', '18:00:00'
FROM attendance_schedules s
JOIN (SELECT 1 AS weekday UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) d
WHERE s.is_default = 1;

INSERT IGNORE INTO attendance_absence_types (code, name, color, is_vacation, is_paid, requires_approval, sort_order) VALUES
('FER', 'Férias', '#26B99A', 1, 1, 1, 10),
('FJ', 'Falta justificada', '#3498DB', 0, 1, 1, 20),
('FI', 'Falta injustificada', '#E74C3C', 0, 0, 1, 30),
('DOE', 'Doença / baixa médica', '#F39C12', 0, 0, 1, 40),
('FOR', 'Formação', '#9B59B6', 0, 1, 1, 50),
('SEXT', 'Serviço externo', '#1ABC9C', 0, 1, 0, 60),
('PAR', 'Licença parental', '#E91E63', 0, 1, 1, 70),
('CH', 'Compensação de horas', '#73879C', 0, 1, 1, 80);
