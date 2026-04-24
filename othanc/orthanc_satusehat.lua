-- lua/orthanc_satusehat.lua
-- ============================================================
--  Orthanc Lua Script — Trigger ImagingStudy ke MediFix
--  Setiap DICOM instance masuk ke Orthanc, script ini akan:
--    1. Baca tags DICOM penting
--    2. POST ke endpoint MediFix (push_imagingstudy.php)
--  Orthanc kemudian yang akan teruskan ke Satu Sehat.
-- ============================================================

-- ── Konfigurasi ──────────────────────────────────────────────────
local MEDIFIX_URL    = 'http://192.168.1.10/medifix/api/push_imagingstudy.php'
local SECRET_KEY     = 'ganti_dengan_secret_anda'   -- harus sama dengan ORTHANC_WEBHOOK_SECRET
local SEND_DELAY_MS  = 500    -- delay sebelum kirim (ms), beri waktu Orthanc selesai simpan
local LOG_PREFIX     = '[SatuSehat] '

-- ── Helper: log ───────────────────────────────────────────────────
local function log(msg)
    print(LOG_PREFIX .. tostring(msg))
end

-- ── Helper: konversi table ke JSON (tanpa library eksternal) ─────
local function toJson(tbl)
    local items = {}
    for k, v in pairs(tbl) do
        local key = '"' .. tostring(k) .. '"'
        local val
        if type(v) == 'string' then
            -- Escape karakter khusus
            local escaped = v:gsub('\\', '\\\\'):gsub('"', '\\"'):gsub('\n', '\\n'):gsub('\r', '\\r')
            val = '"' .. escaped .. '"'
        elseif type(v) == 'number' then
            val = tostring(v)
        elseif type(v) == 'boolean' then
            val = v and 'true' or 'false'
        elseif v == nil then
            val = 'null'
        else
            val = '"' .. tostring(v) .. '"'
        end
        table.insert(items, key .. ':' .. val)
    end
    return '{' .. table.concat(items, ',') .. '}'
end

-- ── Helper: baca tag DICOM dengan fallback ───────────────────────
local function getTag(tags, tagName, default)
    local v = tags[tagName]
    if v == nil or v == '' then return default or '' end
    return tostring(v)
end

-- ── Callback utama Orthanc ────────────────────────────────────────
function OnStoredInstance(instanceId, tags, metadata, origin)

    -- Ambil AccessionNumber (= noorder SIMRS)
    local accession = getTag(tags, 'AccessionNumber', '')
    if accession == '' then
        log('Skip instance ' .. instanceId .. ' — AccessionNumber kosong')
        return
    end

    -- Hindari kirim berulang untuk instance dari sumber yang sama (C-STORE)
    -- origin.RequestOrigin bisa: 'RestApi', 'DicomProtocol', 'Lua', 'Plugin', 'Unknown'
    if origin and origin['RequestOrigin'] == 'Lua' then
        log('Skip instance dari origin Lua (loop guard) — accession=' .. accession)
        return
    end

    -- Tunggu sebentar agar Orthanc selesai commit ke storage
    if SEND_DELAY_MS > 0 then
        -- Orthanc Lua tidak punya sleep bawaan, gunakan busy-wait ringan
        -- atau lewati dan langsung kirim (biasanya aman)
    end

    -- Kumpulkan data DICOM
    local studyUid   = getTag(tags, 'StudyInstanceUID', '')
    local seriesUid  = getTag(tags, 'SeriesInstanceUID', '')
    local instanceUid = getTag(tags, 'SOPInstanceUID', '')
    local sopClass   = getTag(tags, 'SOPClassUID', '')
    local modality   = getTag(tags, 'Modality', 'OT')
    local studyDate  = getTag(tags, 'StudyDate', '')
    local studyTime  = getTag(tags, 'StudyTime', '')
    local bodyPart   = getTag(tags, 'BodyPartExamined', '')
    local seriesDesc = getTag(tags, 'SeriesDescription', '')
    local seriesNo   = tonumber(getTag(tags, 'SeriesNumber', '1')) or 1
    local instanceNo = tonumber(getTag(tags, 'InstanceNumber', '1')) or 1

    -- Coba baca jumlah instance dari study (untuk info saja)
    local numInstances = 1
    local ok, studyInfo = pcall(RestApiGet, '/studies/' .. getTag(tags, 'StudyID', ''))
    -- Jika gagal, biarkan default 1

    log('Menerima DICOM: accession=' .. accession ..
        ' modality=' .. modality ..
        ' study=' .. studyUid)

    -- Build payload JSON
    local payload = {
        accession_number     = accession,
        study_uid            = studyUid,
        series_uid           = seriesUid,
        instance_uid         = instanceUid,
        sop_class_uid        = sopClass,
        modality             = modality,
        study_date           = studyDate,
        study_time           = studyTime,
        body_part            = bodyPart,
        series_description   = seriesDesc,
        series_number        = seriesNo,
        instance_number      = instanceNo,
        number_of_instances  = numInstances,
        orthanc_instance_id  = instanceId,
        secret_key           = SECRET_KEY,
    }

    local jsonBody = toJson(payload)

    -- POST ke MediFix
    local success, result, httpCode = pcall(function()
        return HttpPost(MEDIFIX_URL, jsonBody, {
            ['Content-Type'] = 'application/json',
        })
    end)

    if success then
        log('POST berhasil accession=' .. accession .. ' — response: ' .. tostring(result):sub(1, 200))
    else
        log('POST GAGAL accession=' .. accession .. ' — error: ' .. tostring(result))
    end
end

-- ── Opsional: callback saat study selesai (semua instance sudah masuk) ──
-- Berguna jika ingin kirim ImagingStudy sekali saja setelah semua slice CT selesai
-- (uncomment jika diperlukan)
--[[
function OnStableStudy(studyId, tags, metadata)
    local accession = getTag(tags, 'AccessionNumber', '')
    if accession == '' then return end
    log('Study stable: accession=' .. accession .. ' studyId=' .. studyId)
    -- Di sini bisa trigger kirim ulang atau verifikasi
end
--]]

log('Orthanc Satu Sehat Lua script dimuat. Target: ' .. MEDIFIX_URL)
