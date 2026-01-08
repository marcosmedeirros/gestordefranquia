-- Adiciona coluna para nome do arquivo do edital
ALTER TABLE league_settings 
ADD COLUMN IF NOT EXISTS edital_file VARCHAR(255) NULL AFTER edital;

-- Comentário: O arquivo será salvo em /uploads/editais/{league}_edital.pdf
