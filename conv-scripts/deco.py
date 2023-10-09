import argparse
from bs4 import BeautifulSoup
from dataclasses import dataclass
from enum import StrEnum
from datetime import datetime, timezone
from dateutil import parser as dateparser
from typing import List
import re
import mysql.connector
import traceback
import xxhash
import os
from urllib import parse as urlparse
import shutil
import requests

parser = argparse.ArgumentParser()
parser.add_argument("input")
parser.add_argument("-host", required=True)
parser.add_argument("-u", required=True)
parser.add_argument("-p", required=True)
parser.add_argument("-db", required=True)
parser.add_argument("-t", required=True)
parser.add_argument("-assetdir")

args = parser.parse_args()

f = open(args.input, "r")
html_doc = f.read()
f.close()

basedir = os.path.dirname(args.input)

soup = BeautifulSoup(html_doc, 'html.parser')

authors = { }

author_id = 0

class UserType(StrEnum):
    NORMAL = "user"
    WEBHOOK = "webhook"
    BOT = "bot"

@dataclass
class Author:
    uid: int
    name: str = 'Anonymous'
    avatar_url: str | None = None
    user_type: UserType = UserType.NORMAL

    def __repr__(self):
        return f'[{self.uid}] [{self.user_type}] {self.name}'

class Asset:
    _type: str
    url: str
    og_name: str
    size: int
    xxh128: str

    def __init__(self, path, _type, og_name = None):
        path = urlparse.unquote(path)
        self.url = path
        if (og_name):
            self.og_name = og_name
        else:
            self.og_name = self.extract_og_url(os.path.basename(path))

        self._type = _type
        path = os.path.join(basedir, path)
        self.size = os.path.getsize(path)
        f = open(path, 'rb')
        self.xxh128 = xxhash.xxh128(f.read()).hexdigest()
        f.close()

    def extract_og_url(self, url) -> str:
        m = re.match(r'^(.*)-[A-z0-9]{5}(.*)$', url)
        if m:
            return m.group(1) + m.group(2)
        else:
            return url

    def convert_copy(self, target_dir, prefix):
        new_dir = os.path.join(target_dir, prefix, self.og_name)
        new_url = os.path.join("assets", prefix, self.og_name)
        b = os.path.dirname(new_dir)
        os.makedirs(b, exist_ok=True)
        shutil.copy2(os.path.join(basedir, self.url), new_dir)
        self.url = new_url

@dataclass
class Embed:
    url: str | None = None
    type_: str = 'link'
    color: str | None = None
    footer: str | None = None
    footer_url: str | None = None
    provider: str | None = None
    provider_url: str | None = None
    author: str | None = None
    author_url: str | None = None
    title: str | None = None
    title_url: str | None = None
    description: str | None = None
    embed_url: str | None = None
    asset: Asset | None = None

    def is_empty(self):
        return not any([
            self.url,
            self.footer,
            self.provider,
            self.author,
            self.title,
            self.description,
            self.embed_url,
            self.asset
        ])

    def __repr__(self):
        return f"        {self.author}\n        {self.title}\n        {self.description}"

@dataclass
class Message:
    uid: int
    author_id: int
    content: str | None
    date: datetime
    replies_to: int | None = None
    sticker: int | None = None
    attachments: List[Asset] | None = None
    embed: List[Embed] | None = None

def parse_datetime(dt: str | None) -> datetime:
    if not dt:
        return dateparser.parse("1970-01-01 00:00")

    date = dateparser.parse(dt)
    date.replace(tzinfo=datetime.now(timezone.utc).astimezone().tzinfo)
    return date.astimezone(timezone.utc);

def try_find_timestamp(chatmsg):
    # case 1: chatlog__timestamp in header
    ts = chatmsg.find(class_='chatlog__timestamp')
    if ts:
        return ts.a.contents[0]

    # case 2: chatlog__short-timestamp, full timestamp in title
    ts = chatmsg.find(class_='chatlog__short-timestamp')
    if ts:
        return ts['title']
    else:
        return None

# finds id or None if not present
def find_author_id(msg) -> int | None:
    a = msg.find(class_='chatlog__author')
    if a:
        return int(a['data-user-id'])
    else:
        return None

# returns author or None if not present
def find_author(msg) -> Author | None:
    a = msg.find(class_='chatlog__author')
    if a:
        author = Author(
            uid = int(a['data-user-id']),
            name = a.contents[0]
        )
    else:
        return None
    
    a = msg.find(class_='chatlog__avatar')
    if a:
        author.avatar_url = a['src']

    bot = msg.find(class_='chatlog__bot-label')
    if bot:
        author.user_type = UserType.BOT
    else:
        author.user_type = UserType.NORMAL

    return author

re_emoji = re.compile(r'.+cdn.discordapp.com\/emojis\/([0-9]+).')

def parse_markdown(content):
    # mentions
    for m in content.find_all(class_='chatlog__markdown-mention'):
        m.string = f'<{m.string}>'
        m.unwrap()

    # motes
    for e in content.find_all(class_='chatlog__emoji'):
        emote = re_emoji.match(e['src'])
        if emote:
            emote_tag = e["title"]
            e.replace_with(f'<:{emote_tag}:{emote.group(1)}>')
        else:
            e.replace_with(e['alt'])

    # links
    for l in content.find_all('a'):
        l.unwrap()

    # em
    for em in content.find_all('em'):
        em.replace_with(f'*{em.string}*')

    # bold
    for b in content.find_all('b'):
        b.replace_with(f'**{b.string}**')

    # inline code
    for c in content.find_all('code'):
        c.replace_with(f'`{c.string}`')

    content.smooth()

    return str(content.string) if content.string else None

def parse_attachments(chatmsg) -> List[Asset] | None:
    attachments = []

    for a in chatmsg.find_all(class_='chatlog__attachment'):
        media = a.find(class_='chatlog__attachment-media')
        if not media:
            continue

        if media.name == 'img':
            attachments.append(
                Asset(media['src'], 'image'))
        if media.name == 'video':
            attachments.append(
                Asset(media.source['src'], 'video'))
        if media.name == 'audio':
            attachments.append(
                Asset(media.source['src'], 'audio'))

    if len(attachments) > 0:
        return attachments
    else:
        return None

def parse_embed(e) -> Embed | None:
    embed = Embed()
    
    url = e.find(class_='chatlog__embed-author-link')
    if url:
        embed.author_url = str(url['href'])

    author = e.find(class_='chatlog__embed-author')
    if author:
        embed.author = str(author.string)
    
    title = e.find(class_='chatlog__embed-title')
    if title:
        if title.a:
            embed.title_url = str(title.a['href'])
        content = title.find(class_='chatlog__markdown-preserve')
        if content:
            embed.title = parse_markdown(content)

    desc = e.find(class_='chatlog__embed-description')
    if desc:
        content = desc.find(class_='chatlog__markdown-preserve')
        if content:
            content = parse_markdown(content)
            embed.description = content

    img = e.find(class_='chatlog__embed-plainimage')
    if img:
        a = Asset(img['src'], 'image')
        embed.asset = a
        embed.type_ = 'image'

    img = e.find(class_='chatlog__embed-image')
    if img:
        a = Asset(img['src'], 'image')
        embed.asset = a

    footer = e.find(class_='chatlog__embed-footer-text')
    if footer:
        embed.footer = str(footer.string)

    color = e.find(class_='chatlog__embed-color-pill')
    if color:
        if 'chatlog__embed-color-pill--default' not in color['class']:
            m = re.match(r'background-color:(.*)', str(color['style']))
            if m:
                embed.color = m.group(1)
                print(e.color)

    yt = e.find(class_='chatlog__embed-youtube')
    if yt:
        embed.embed_url = str(yt['src'])

    return embed

def parse_embeds(chatmsg) -> List[Embed] | None:
    embeds = []

    for e in chatmsg.find_all(class_='chatlog__embed'):
        embeds.append(parse_embed(e))

    return embeds if len(embeds) else None

def try_download_asset(url: str, basedst: str, ext) -> str | None:
    fn = xxhash.xxh128(url).hexdigest() + ext
    dst = os.path.join(basedst, fn)
    if os.path.exists(dst):
        print(f"'{dst}' exists, skipping download")
        return dst
    r = requests.get(url, stream=True)
    if r.status_code == requests.codes.ok:
        with open(dst, "wb") as f:
            for ch in r.iter_content(chunk_size=1024):
                f.write(ch)
        return dst
    else:
        print(f"failed to download '{url}': {r.status_code}")

def find_urls(msg: str):
    matches = re.findall(
        r'http[s]?://(?:[a-zA-Z]|[0-9]|[$-_@.&+]|[!*\(\),]|(?:%[0-9a-fA-F][0-9a-fA-F]))+',
        msg
    )
    return matches

def restore_video_embeds(msg: str):
    embeds = []
    for url in find_urls(msg):
        if ".mp4" in url:
            fp = try_download_asset(url, args.assetdir, ".mp4")
            if not fp:
                continue
            a = Asset(fp, 'video')
            embeds.append(Embed(url=url, asset=a, type_='video'))
    return embeds

reply_id = re.compile(r"scrollToMessage\(event,'(\d+)'\)")
re_sticker = re.compile(r'.+\/([\d]+)-[\w]+\.[\w]+')

def parse_message(msg, author_id) -> Message:
    msg_id = int(msg.parent['data-message-id'])

    new_id = find_author_id(msg)
    if new_id:
        author_id = new_id
        if new_id not in authors:
            author = find_author(msg)
            authors[new_id] = author

    timestamp = parse_datetime(try_find_timestamp(msg))

    content = msg.find('span', class_='chatlog__markdown-preserve')
    if content:
        content = parse_markdown(content)

    sticker = msg.find(class_='chatlog__sticker')
    if sticker:
        sticker = re_sticker.match(sticker.img['src'])
        if not sticker:
            raise ValueError('failed to match sticker id in url')
        sticker = int(sticker.group(1))

    reply = msg.find(class_='chatlog__reference-link')
    if reply:
        reply = reply_id.match(reply['onclick'])
        if reply:
            reply = int(reply.group(1))

    attachments = parse_attachments(msg)
    embeds = parse_embeds(msg)
    ea = restore_video_embeds(content if content else '')
    if embeds:
        embeds.extend(ea)
    else:
        embeds = ea

    message = Message(
        msg_id,
        author_id,
        content,
        timestamp,
        sticker=sticker,
        attachments=attachments,
        embed=embeds,
        replies_to=reply)

    return message

def mention_to_id(match, authors_by_name):
    name = match.group(1)
    if name in authors_by_name:
        return f'<@{authors_by_name[name].uid}>'
    else:
        return f'@{name}'

def resolve_mentions_to_id(content, authors_by_name):
    return re.sub(
        r'<@([\w ]+)>',
        lambda match: mention_to_id(match, authors_by_name),
        content)

def insert_asset(cursor, a, prefix):
    cursor.execute("SELECT id FROM assets WHERE hash = %s LIMIT 1", [a.xxh128])
    aq = cursor.fetchone()
    if aq:
        print(f"asset exists: {aq[0]}")
        return aq[0]

    if args.assetdir:
        a.convert_copy(args.assetdir, prefix)

    cursor.execute("""
        INSERT INTO assets (type, og_name, url, hash, size)
        VALUES (%s, %s, %s, %s, %s)""",
        [a._type, a.og_name, a.url, a.xxh128, a.size]
    )
    return cursor.lastrowid

msgs = {}

for msg in soup.find_all('div', class_='chatlog__message'):
    m = parse_message(msg, author_id)
    author_id = m.author_id

    msgs[m.uid] = m

    if m.replies_to:
        rep = msgs[m.replies_to]
        print(f'::: REPLY TO ::: {authors[rep.author_id].name}: {rep.content}')

    if m.content:
        print(f'[{m.date}] {authors[m.author_id].name}: {m.content}')
    elif m.sticker:
        print(f'[{m.date}] {authors[m.author_id].name}: [sticker: {m.sticker}]')
    else:
        print(f'[{m.date}] {authors[m.author_id].name}: [no content]')

    if m.attachments:
        attachtypes = []
        for a in m.attachments:
            attachtypes.append(a._type)

        print('Attachments: ' + ', '.join(attachtypes))

    if m.embed:
        print(m.embed)

for au in authors.items():
    print(au[1])

authors_by_name = {}

for key, a in authors.items():
    authors_by_name[a.name] = a

for key, msg in msgs.items():
    if not msg.content:
        continue
    msg.content = resolve_mentions_to_id(msg.content, authors_by_name)

# mysql sutff

connection = mysql.connector.connect(
    host=args.host,
    user=args.u,
    passwd=args.p,
    database=args.db,
    charset="utf8mb4")

cursor = connection.cursor()

query = """
INSERT IGNORE INTO authors (id, display_name, type, avatar_asset)
VALUES (%s, %s, %s, %s);
"""
vals = []

for au in authors.items():
    a = au[1]
    a.name = str(a.name) if a.name else None
    a.avatar_url = str(a.avatar_url) if a.avatar_url else None
    asset_id = insert_asset(cursor, Asset(a.avatar_url, "image"), "avi")
    vals.append((a.uid, a.name, str(a.user_type), asset_id))

try:
    cursor.executemany(query, vals)
    connection.commit()
except Exception as e:
    traceback.print_exc()

query = f"""
REPLACE INTO {args.t} 
(id, author_id, sent, modified, replies_to, content, sticker, attachment_group, embed_group) 
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s);
"""
vals = []

for m in msgs.items():
    msg: Message = m[1]
    msg.content = str(msg.content) if msg.content else None
    msg.sticker = int(msg.sticker) if msg.sticker else None
    msg.replies_to = int(msg.replies_to) if msg.replies_to else None
    attachment_id = None
    if msg.attachments:
        cursor.execute("INSERT INTO attachment_groups (id) VALUES (NULL)")
        attachment_id = cursor.lastrowid
        for a in msg.attachments:
            asset_id = insert_asset(cursor, a, str(attachment_id))
            cursor.execute("INSERT INTO attachments (group_id, asset_id) VALUES (%s, %s)",
                [attachment_id, asset_id])

    embed_id = None
    if msg.embed:
        cursor.execute("INSERT INTO embed_groups (id) VALUES (NULL)")
        embed_id = cursor.lastrowid
        for e in msg.embed:
            if e.is_empty():
                print("===== EMPTY EMBED")
                continue

            asset_id = None
            if (e.asset):
                asset_id = insert_asset(cursor, e.asset, "embed/" + str(embed_id))

            cursor.execute("""
                INSERT INTO embeds 
                (group_id,     type,         color,   footer,
                 author,       author_url,   title,   title_url, 
                 description,  embed_url,    asset_id)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                [embed_id,     e.type_,      e.color, e.footer,
                 e.author,     e.author_url, e.title, e.title_url,
                 e.description,e.embed_url,  asset_id])

    val = (msg.uid, msg.author_id,
        msg.date.strftime('%Y-%m-%d %H:%M:%S'), None,
        msg.replies_to, msg.content, msg.sticker, attachment_id, embed_id)
    vals.append(val)

try:
    cursor.executemany(query, vals)
    connection.commit()
except Exception as e:
    traceback.print_exc()
