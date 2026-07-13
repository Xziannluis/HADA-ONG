const Busboy = require("busboy");
const { randomBytes } = require("crypto");

const PASSWORD = process.env.BIRTHDAY_PASSWORD || "071703";
const SUPABASE_URL = process.env.SUPABASE_URL || "";
const SUPABASE_SERVICE_ROLE_KEY = process.env.SUPABASE_SERVICE_ROLE_KEY || "";
const SUPABASE_BUCKET = process.env.SUPABASE_BUCKET || "birthday-media";

const json = (statusCode, body) => ({
  statusCode,
  headers: { "content-type": "application/json" },
  body: JSON.stringify(body),
});

const requireSupabase = () => {
  if (!SUPABASE_URL || !SUPABASE_SERVICE_ROLE_KEY) {
    throw new Error("Supabase is not configured yet.");
  }
};

const supabaseFetch = async (path, options = {}) => {
  requireSupabase();
  const response = await fetch(`${SUPABASE_URL}${path}`, {
    ...options,
    headers: {
      apikey: SUPABASE_SERVICE_ROLE_KEY,
      authorization: `Bearer ${SUPABASE_SERVICE_ROLE_KEY}`,
      ...(options.headers || {}),
    },
  });

  const text = await response.text();
  let data = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = text;
    }
  }

  if (!response.ok) {
    const message = data?.message || data?.error || "Supabase request failed.";
    throw new Error(message);
  }

  return data;
};

const cleanText = (value, limit) =>
  String(value || "")
    .trim()
    .replace(/\s+/g, " ")
    .slice(0, limit);

const cleanMessage = (value, limit) => String(value || "").trim().slice(0, limit);

const parseJsonBody = (event) => {
  if (!event.body) return {};
  const body = event.isBase64Encoded ? Buffer.from(event.body, "base64").toString("utf8") : event.body;
  return JSON.parse(body || "{}");
};

const parseMultipart = (event) =>
  new Promise((resolve, reject) => {
    const fields = {};
    const files = {};
    const headers = {
      "content-type": event.headers["content-type"] || event.headers["Content-Type"],
    };
    const busboy = Busboy({ headers });
    const body = event.isBase64Encoded ? Buffer.from(event.body || "", "base64") : Buffer.from(event.body || "");

    busboy.on("field", (name, value) => {
      fields[name] = value;
    });

    busboy.on("file", (name, stream, info) => {
      const chunks = [];
      stream.on("data", (chunk) => chunks.push(chunk));
      stream.on("end", () => {
        files[name] = {
          buffer: Buffer.concat(chunks),
          filename: info.filename,
          mimeType: info.mimeType,
        };
      });
    });

    busboy.on("error", reject);
    busboy.on("finish", () => resolve({ fields, files }));
    busboy.end(body);
  });

const randomName = (filename = "upload") => {
  const extension = filename.includes(".") ? filename.split(".").pop().toLowerCase().replace(/[^a-z0-9]/g, "") : "bin";
  const token = randomBytes(16).toString("hex");
  return `${token}.${extension || "bin"}`;
};

const uploadFile = async (file, folder, allowedTypes, maxBytes) => {
  if (!file || !file.buffer?.length) return "";
  if (!allowedTypes.includes(file.mimeType)) {
    throw new Error("Unsupported file type.");
  }
  if (file.buffer.length > maxBytes) {
    throw new Error("One of the uploaded files is too large.");
  }

  const objectPath = `${folder}/${randomName(file.filename)}`;
  await supabaseFetch(`/storage/v1/object/${SUPABASE_BUCKET}/${objectPath}`, {
    method: "POST",
    headers: {
      "content-type": file.mimeType,
      "x-upsert": "false",
    },
    body: file.buffer,
  });

  return `${SUPABASE_URL}/storage/v1/object/public/${SUPABASE_BUCKET}/${objectPath}`;
};

const removeStorageObject = async (publicUrl) => {
  if (!publicUrl || !publicUrl.includes(`/storage/v1/object/public/${SUPABASE_BUCKET}/`)) return;
  const objectPath = publicUrl.split(`/storage/v1/object/public/${SUPABASE_BUCKET}/`).pop();
  if (!objectPath) return;

  await supabaseFetch(`/storage/v1/object/${SUPABASE_BUCKET}`, {
    method: "DELETE",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ prefixes: [decodeURIComponent(objectPath)] }),
  });
};

exports.handler = async (event) => {
  if (event.httpMethod !== "POST") {
    return json(405, { error: "Method not allowed." });
  }

  try {
    const contentType = event.headers["content-type"] || event.headers["Content-Type"] || "";
    const isMultipart = contentType.includes("multipart/form-data");
    const parsed = isMultipart ? await parseMultipart(event) : { fields: parseJsonBody(event), files: {} };
    const body = parsed.fields;
    const files = parsed.files;
    const action = body.action || "";

    if (action === "login") {
      return cleanText(body.password, 40) === PASSWORD
        ? json(200, { ok: true })
        : json(401, { error: "Incorrect password. Try again." });
    }

    if (action === "checkSession") {
      return json(200, { authenticated: cleanText(body.password, 40) === PASSWORD });
    }

    if (action === "logout") {
      return json(200, { ok: true });
    }

    if (action === "submitWish") {
      const senderName = cleanText(body.name, 80);
      const relationship = cleanText(body.relationship, 80);
      const message = cleanMessage(body.message, 1500);

      if (!senderName || !relationship || !message) {
        return json(422, { error: "Name, relationship, and message are required." });
      }

      const photoPath = await uploadFile(
        files.photo,
        "photos",
        ["image/jpeg", "image/png", "image/gif", "image/webp"],
        8 * 1024 * 1024
      );
      const videoPath = await uploadFile(
        files.video,
        "videos",
        ["video/mp4", "video/webm", "video/ogg", "video/quicktime"],
        80 * 1024 * 1024
      );

      await supabaseFetch("/rest/v1/wishes", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          prefer: "return=minimal",
        },
        body: JSON.stringify({
          sender_name: senderName,
          relationship,
          message,
          photo_path: photoPath,
          video_path: videoPath,
        }),
      });

      return json(200, { ok: true, message: "Your message was sent." });
    }

    if (action === "listWishes") {
      if (cleanText(body.password, 40) !== PASSWORD) {
        return json(401, { error: "Birthday access required." });
      }

      const rows = await supabaseFetch(
        "/rest/v1/wishes?select=id,sender_name,relationship,message,photo_path,video_path,created_at&order=created_at.desc,id.desc"
      );
      const wishes = rows.map((row) => ({
        id: row.id,
        senderName: row.sender_name,
        relationship: row.relationship,
        message: row.message,
        photoPath: row.photo_path || "",
        videoPath: row.video_path || "",
        createdAt: row.created_at,
      }));

      return json(200, { wishes });
    }

    if (action === "deleteWish") {
      if (cleanText(body.password, 40) !== PASSWORD) {
        return json(401, { error: "Birthday access required." });
      }

      const id = Number.parseInt(body.id, 10);
      if (!id) return json(422, { error: "Invalid wish." });

      const rows = await supabaseFetch(`/rest/v1/wishes?id=eq.${id}&select=photo_path,video_path`);
      await supabaseFetch(`/rest/v1/wishes?id=eq.${id}`, { method: "DELETE" });

      if (rows[0]) {
        await removeStorageObject(rows[0].photo_path);
        await removeStorageObject(rows[0].video_path);
      }

      return json(200, { ok: true });
    }

    return json(400, { error: "Unknown action." });
  } catch (error) {
    return json(500, { error: error.message || "Server error." });
  }
};
