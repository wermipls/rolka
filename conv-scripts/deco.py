import argparse
from bs4 import BeautifulSoup
from dataclasses import dataclass
from enum import IntEnum
from datetime import datetime
from dateutil import parser as dateparser
from typing import List
import re
import mysql.connector
import traceback

parser = argparse.ArgumentParser()
parser.add_argument("input")
parser.add_argument("-host", required=True)
parser.add_argument("-u", required=True)
parser.add_argument("-p", required=True)
parser.add_argument("-db", required=True)
parser.add_argument("-t", required=True)

args = parser.parse_args()

f = open(args.input, "r")
html_doc = f.read()
f.close()

soup = BeautifulSoup(html_doc, 'html.parser')

authors = { }

author_id = 0

class UserType(IntEnum):
    NORMAL = 1
    WEBHOOK = 2
    BOT = 3

@dataclass
class Author:
    uid: int
    name: str = 'Anonymous'
    avatar_url: str | None = None
    user_type: UserType = UserType.NORMAL

    def __repr__(self):
        return f'[{self.uid}] [{self.user_type}] {self.name}'

@dataclass
class Attachment:
    attach_type: str
    url: str

@dataclass
class Embed:
    author: str | None = None
    author_url: str | None = None
    title: str | None = None
    title_url: str | None = None
    description: str | None = None
    special_container_url: str | None = None

    def __repr__(self):
        return f"        {self.author}\n        {self.title}\n        {self.description}"

@dataclass
class Message:
    uid: int
    author_id: int
    content: str | None
    date: datetime
    replies_to: int | None = None
    sticker: str | None = None
    attachments: List[Attachment] | None = None
    embed: Embed | None = None

def parse_datetime(dt: str | None) -> datetime:
    if not dt:
        return dateparser.parse("1970-01-01 00:00")

    return dateparser.parse(dt)

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
def find_author_id(chatmsg) -> int | None:
    a = msg.find(class_='chatlog__author')
    if a:
        return int(a['data-user-id'])
    else:
        return None

# returns author or None if not present
def find_author(chatmsg) -> Author | None:
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

    return content.string

def parse_attachments(chatmsg) -> List[Attachment] | None:
    attachments = []

    for a in chatmsg.find_all(class_='chatlog__attachment'):
        media = a.find(class_='chatlog__attachment-media')
        if not media:
            continue

        if media.name == 'img':
            attachments.append(
                Attachment('image', media['src']))
        if media.name == 'video':
            attachments.append(
                Attachment('video', media.source['src']))
        if media.name == 'audio':
            attachments.append(
                Attachment('audio', media.source['src']))

    if len(attachments) > 0:
        return attachments
    else:
        return None

def parse_embed(chatmsg) -> Embed | None:
    e = chatmsg.find(class_='chatlog__embed')
    if not e:
        return None

    embed = Embed()
    
    url = e.find(class_='chatlog__embed-author-link')
    if url:
        embed.author_url = url['href']

    author = e.find(class_='chatlog__embed-author')
    if author:
        embed.author = author.string
    
    title = e.find(class_='chatlog__embed-title')
    if title:
        if title.a:
            embed.title_url = title.a['href']
        content = title.find(class_='chatlog__markdown-preserve')
        if content:
            embed.title = parse_markdown(content)

    desc = e.find(class_='chatlog__embed-description')
    if desc:
        content = desc.find(class_='chatlog__markdown-preserve')
        if content:
            content = parse_markdown(content)
            embed.description = content

    return embed

reply_id = re.compile(r"scrollToMessage\(event,'(\d+)'\)")

def parse_message(chatmsg, author_id) -> Message:
    msg_id = int(chatmsg.parent['data-message-id'])

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
        sticker = sticker.img['src']

    reply = msg.find(class_='chatlog__reference-link')
    if reply:
        reply = reply_id.match(reply['onclick'])
        if reply:
            reply = int(reply.group(1))

    attachments = parse_attachments(chatmsg)
    embed = parse_embed(chatmsg)

    message = Message(
        msg_id,
        author_id,
        content,
        timestamp,
        sticker=sticker,
        attachments=attachments,
        embed=embed,
        replies_to=reply)

    return message

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
            attachtypes.append(a.attach_type)

        print('Attachments: ' + ', '.join(attachtypes))

    if m.embed:
        print(m.embed)

for au in authors.items():
    print(au[1])


# mysql sutff

connection = mysql.connector.connect(
    host=args.host,
    user=args.u,
    passwd=args.p,
    database=args.db)

query = """
CREATE TABLE IF NOT EXISTS tp_authors (
    uid BIGINT NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    avatar_url VARCHAR(500),
    utype INT
);

CREATE TABLE IF NOT EXISTS tp_channel_oldgeneral (
    uid BIGINT NOT NULL PRIMARY KEY,
    author_id BIGINT NOT NULL,
    content TEXT
    sticker TEXT
);
"""

cursor = connection.cursor()
cursor.execute(query, multi=True)

query = f"""
REPLACE INTO {args.t} (uid, author_id, date_sent, content, sticker, attachment, replies_to) 
VALUES (%s, %s, %s, %s, %s, %s, %s);
"""
vals = []

for m in msgs.items():
    msg = m[1]
    msg.content = str(msg.content) if msg.content else None
    msg.sticker = str(msg.sticker) if msg.sticker else None
    msg.replies_to = str(msg.replies_to) if msg.replies_to else None
    attachment_id = None
    if msg.attachments:
        cursor.execute("SELECT MAX(id) FROM tp_attachments")
        last_id = cursor.fetchone()
        last_id = int(last_id[0]) if last_id else 1

        attachment_id = last_id + 1

        for a in msg.attachments:
            cursor.execute(
                "INSERT INTO tp_attachments (id, type, url) VALUES (%s, %s, %s)",
                [attachment_id, a.attach_type, a.url]
            )

    val = (msg.uid, msg.author_id, 
        msg.date.strftime('%Y-%m-%d %H:%M:%S'),
        msg.content, msg.sticker, attachment_id, msg.replies_to)
    vals.append(val)

try:
    cursor.executemany(query, vals)
    connection.commit()
except Exception as e:
    traceback.print_exc()

query = """
INSERT IGNORE INTO tp_authors (uid, name, avatar_url, utype)
VALUES (%s, %s, %s, %s);
"""
vals = []

for au in authors.items():
    a = au[1]
    a.name = str(a.name) if a.name else None
    a.avatar_url = str(a.avatar_url) if a.avatar_url else None
    vals.append((a.uid, a.name, a.avatar_url, int(a.user_type)))

try:
    cursor.executemany(query, vals)
    connection.commit()
except Exception as e:
    traceback.print_exc()